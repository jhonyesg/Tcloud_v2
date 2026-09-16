# Descubrimiento: API Transcriptor — Estado del Pipeline (2026-08-11)

## Contexto

Investigación del módulo API Transcriptor (`/ia/api-transcriptor`) tras
preguntas del admin sobre si las transcripciones se están enviando,
cuánto tardan y si el sistema está sobrecargado. La pista inicial fue
que nada estaba llegando a `state=done`.

## Arquitectura confirmada

```
StorageProvider (transcription_enabled=true)
      │
      ▼  tick cada 2 min
ConvertAndTranscribeJob (col Redis 'transcription')
      │
      ▼
TranscriptionSubmitService::submit()
      │  ffmpeg → /dev/shm/...opus
      ▼
POST /v1/transcribe (sin callback_url)
      │
      ▼  state=queued, job_id asignado
TranscriptionPollingService::pollAll()  ← cada 1 min
      │  GET /v1/jobs/{job_id}
      ▼  si state=done
TranscriptionProcessor::processDoneWithSrt()
      │  descarga SRT, parsea, inserta segments
      ▼
state=done, transcription_segments insertados
```

Servicios clave:
- `app/app/Jobs/ConvertAndTranscribeJob.php:25`
- `app/app/Services/Ia/TranscriptionSubmitService.php:31`
- `app/app/Services/Ia/TranscriptionPollingService.php:32`
- `app/app/Services/Ia/TranscriptionProcessor.php:37`

Workers: 12 unidades systemd `tcloud-transcription-batch-N.service`,
comando `php artisan queue:work --queue=transcription --tries=3 --timeout=600 --sleep=1`.

## Métricas capturadas (2026-08-11 ~12:15 hora de Bogotá)

### Inventario de medios con transcripción habilitada

- **39 storages** con `transcription_enabled=true`:
  - **38 directos** (tienen medios físicos)
  - **1 contenedor** (sp=47 "01 Emisoras 01" — prefijo de sp=63, debería tener
    `transcription_enabled=false` por la regla
    `disable_transcription_on_container_storages`)

### Desglose por tipo

| Tipo | Storages | Comportamiento |
|---|---|---|
| TV / Noticieros (25) | 6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,127,128,133,135,169,184,185 | Layout: `storage/AAAA-MM-DD/medio_HHMMSS.mp4`. ~76 archivos/día por storage. Archivos CERRADOS al momento del tick (no se truncan). |
| Emisoras (5) | 47,49,53,55,134,63 | Layout: `storage/<region>/<ciudad>/<medio>/AAAA-MM-DD/archivo.mp3` o `storage/<medio>/AAAA-MM-DD/archivo.mp3`. Radios en vivo: archivo incompleto cuando el tick lo ve. |

### Conteo real de medios únicos en emisoras (validado en disco)

| Storage | Medios | Con archivos | Vacíos |
|---|---:|---:|---:|
| sp=47 01 Emisoras 01 (Bogotá) | 60 | 56 | 4 |
| sp=49 02 Emisoras 01 Reg (RD1) | 34 | 32 | 2 |
| sp=53 05 Emisoras 04 (RD4) | 19 | 18 | 1 |
| sp=55 06 Emisoras 05 (RD6) | 22 | 22 | 0 |
| sp=134 04 Emisoras 03 (Disco_H) | 47 | 45 | 2 |
| **TOTAL** | **182** | **173** | **9** |

### 9 carpetas vacías (no graban)

```
sp=47  COLMUNDO/{10082026,11082026}, RED/{10082026,11082026}
sp=49  Antioquia/Radio_Red, Atlantico/Libertad
sp=53  Boyaca/San_Miguel
sp=134 Bolivar/Stereo_R_Cartagena, Santander/41_Emisora_Jose_Antonio_Galan
```

### Distribución geográfica emisoras (17 regiones)

| Región | Medios |
|---|---:|
| Cundinamarca | 30 (RD4:16 + Emisoras_03:12 + RD6:2) |
| Valle_Cauca | 16 (RD1:10 + Emisoras_03:3 + RD6:3) |
| Antioquia | 14 (RD1:9 + RD4:1 + RD6:4) |
| Bolivar | 12 (Emisoras_03) |
| Bogotá | 12 ciudades TROPICANA_REGIONAL + 4 Emisoras_03 |
| Boyaca | 10 (RD4:2 + RD6:6 + Emis03:2) |
| Atlantico | 8 (RD1) |
| Santander | 7 (Emisoras_03) |
| Tolima | 6 (RD1) |
| Norte_Santander | 6 (RD6:3 + Emisoras_03:3) |
| Narino | 3 (Emisoras_03) |
| Cauca, Caldas, Cesar, Guajira, Guaviare, Sucre | 1 c/u |

## Problemas identificados

### Problema 1: 0 transcripciones `done` en últimas 24h

- **Último `state=done`**: 2026-08-03 08:54:59 → **8 días sin completarse una sola**
- En la última hora: 788 archivos con datos creados, 351 enqueued, 37 dead, **0 done**
- Backlog acumulado: 84,642 transcriptions `queued` esperando

### Problema 2: Workers en crash-loop (workers systemd)

Síntoma: `systemctl status` muestra 8 servicios "running" + 4 "activating
(auto-restart)" en bucle continuo de ~6s cada uno.

Causa: `storage/logs/laravel.log` es propiedad de `www-data` (755) pero los
workers corren como `User=www`/`Group=www`. Cualquier `Log::error()` en el
catch de `TranscriptionSubmitService::markError()` lanza
`Permission denied` y mata el worker con exit 1.

Evidencia:
```
$ ls -l storage/logs/laravel.log
-rwxr-xr-x 1 www-data www-data 7698805 Aug 11 12:15 laravel.log

$ sudo -u www bash -c "echo test >> storage/logs/laravel.log"
bash: ...laravel.log: Permission denied
```

Fix (1 línea, no requiere cambio de código):
```bash
sudo chown www:www /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log
sudo chmod 664 /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log
```

### Problema 3: Archivos 0-bytes de emisoras matan workers

Cuando el tick programa el dispatch de un archivo de radio en vivo, lo
agarra mientras el grabador aún está escribiendo → archivo de 0 bytes.

ffmpeg falla con:
```
Failed to read frame size: Could not seek to 1026.
... Invalid argument
```

Real example:
```
/Disco_I/television/RD1/Atlantico/Libertad/11082026/
  13_libertad_atlantico_11082026_104201.mp3   ← size = 0 bytes
```

Resultado: 3 retries → state=`dead`. 538 nuevos dead en 24h.

El error en `markError()` dispara `Log::error()` → Problema 2 → worker muere.

### Problema 4: Error message en BD es engañoso

El `error_message` guardado por `TranscriptionSubmitService::markError`
empieza con la **banner de configuración de ffmpeg** (porque proc_open
capturó stderr desde el primer byte), no con la línea de error real.
Hace que filtrar errores en UI sea confuso.

Ejemplo en BD:
```
[Auto] Max retries (3) alcanzado. ffmpeg falló: enable-libxml2 --enable-libxvid
  --enable-libzimg --enable-libzmq --enable-libzvbi --enable-lv2 --enable-omx
  --enable-openal --enable-opencl --enable-opengl --enable-sdl2 --enable-
  pocketsphinx --enable-librsvg --enable-libmfx --enable-libdc1394 --enable-
  libdrm --enable-libiec61883 --enable-libchromaprint --enable-frei0r
  --enable-libx264 --enable-shared
  libavutil ...
```

La línea real de error ("Failed to read frame size") está enterrada mucho
más adelante o se pierde.

## Estado del último encolado (snapshot reciente)

```
=== ULTIMAS 10 transcriptions (head de la cola) ===
id=181193 state=queued     job=Y ret=0 age=18255s err=
id=181192 state=queued     job=Y ret=0 age=18365s err=
...
(5 horas esperando)
```

**Los archivos del head de la cola SÍ existen en disco** (10–20 MB cada
uno). El dispatch funciona (job_id asignado). Lo que está roto es el
último tramo: o el procesamiento de los workers, o el polling no está
viendo `state=done` desde la API externa.

## Datos para diagnóstico futuro

- Threshold: archivo `< 1 KB` → casi seguro corrupto (radio truncado)
- Throughput observado: ~250–700 ms/transcripción cuando el sistema funciona
- 12 workers systemd, capacidad teórica: ~12 × 4 = 48 transcripciones/min
- Demand real: ~700 archivos/h solo de emisoras (~12/min promedio)

## Causa raíz CONFIRMADA: el polling no encuentra los `done`

### Lo que parece (engañoso)

- `transcription:poll-results` corre cada minuto vía cron:
  `* * * * * cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app && php artisan schedule:run`
- Reporta `polled=140, done=0, errors=0, still_pending=140` cada vez
- Conclusión visible: "el polling no recupera nada"

### Lo que realmente pasa

1. **El transcriptor fue reiniciado** (la API externa).
   Endpoint `/api/info` devuelve:
   ```
   "uptime_seconds_since_wakeup": 27
   "last_wakeup_at": 1786475480.8133132
   "last_shutdown_at": 1786475457.0001578
   "workers": 4
   "cluster_state": "UP"
   ```
   El nodo se despertó hace 27s de un shutdown — los jobs en memoria
   pre-shutdown están **perdidos**.

2. **Todos los jobs recientes ESTÁN done** en la API. Sondeo manual:
   ```
   Newest 5 queued IDs:
   id=181972 job=... state=done
   id=181971 job=... state=done
   id=181970 job=... state=done
   id=181969 job=... state=done
   id=181968 job=... state=done
   ```
   Muestra de 500 más recientes: **500/500 = state=done**.

3. **Los jobs viejos (id < 90850) están 404**:
   ```
   id=90837 ERROR: getJob error 404: {"detail":"job no encontrado"}
   id=90843 ERROR: getJob error 404: {"detail":"job no encontrado"}
   ...
   ```
   Estos son de antes del reinicio del transcriptor, perdidos sin
   posibilidad de recuperar SRT.

4. **El polling toma los jobs en orden ascendente por id** (default
   PostgreSQL). Sin `ORDER BY` en `TranscriptionPollingService::pollAll()`:
   ```php
   $jobs = Transcription::whereIn('state', [...]
       ->whereNotNull('job_id')
       ->limit($this->settings->int('poll_limit'))   // 140
       ->get();
   ```
   Los primeros 140 son los MÁS VIEJOS → todos 404 → todos
   `still_pending++`. Los 500 más recientes (que SÍ están done) nunca
   se alcanzan porque el poll se atora en los 404 del inicio.

5. **El `Log::debug` de la pollAll está silenciado**:
   `LOG_LEVEL=warning` en `.env` filtra los `Log::debug` que escribe
   la pollAll. Por eso nadie veía que se estaban catcheando 404s.

### Por qué el usuario "ve que se está procesando"

- La tick cada 2 min sigue creando nuevas Transcriptions y encolando
  jobs (`encolados=45` cada tick, etc.).
- El submitter hace POST /v1/transcribe correctamente → job_id asignado.
- El transcriptor procesa cada uno en 1-3 segundos (visible en el
  `finished` del JSON).
- Solo **la última milla** (poll → process done) está rota.

### Solución propuesta (sin cambio de código, vía SQL)

**Inmediato** (ahora): cambiar el poll para que tome los más recientes
primero. Editar `TranscriptionPollingService::pollAll()`:
```php
->orderBy('id', 'DESC')   // <-- añadir
```

**Para los 85k viejos**: marcar como `dead` los 404s (transcriptor los
perdió tras reinicio) — el SRT ya no se puede recuperar. O re-dispatch
si se considera valioso. Probablemente los más recientes son los
valiosos; los muy viejos (>7 días) podrían simplemente marcarse dead.

```sql
-- Marcar jobs viejos 404 como dead con mensaje claro
UPDATE transcriptions
SET state = 'dead',
    error_message = 'Job perdido tras reinicio del transcriptor (404 not_found)',
    finished_at = NOW()
WHERE state = 'queued'
  AND job_id IS NOT NULL
  AND id < 100000;  -- ajustar umbral
```

### Fixes adicionales en cola

- **laravel.log perms**: ya arreglado en esta sesión
  (`chown www:www` + `chmod 664`).
- **filter 0-byte files** en `TranscriptionSubmitService::submit()` —
  o evitar encolar radios truncados.
- **recover stale_pending** (PollResultsCommand): los errores de
  ffmpeg de los 0-byte radio files saturan la poll cada minuto.

## Pendientes de validación

- [x] Confirmar poll picks newest first (necesita fix de código)
- [x] Medir distribución done/404/queued actual (500/500 done)
- [ ] Decidir si el sp=47 "01 Emisoras 01" debe pasar a
      `transcription_enabled=false` por la convención del proyecto
- [x] Marcar los jobs viejos 404 como `dead` para liberar la cola (832 marcados, cutoff id=92553)
- [x] Implementar filtro de archivos < 1 KB en submit (TranscriptionSubmitService.php:51-62, setting `min_file_size_bytes`)
- [x] Banner de novedades en tab Storages (Aug 2026)

## Banner de novedades (2026-08-11 15:37)

### Componentes

- **Endpoint**: `GET /ia/api-transcriptor/empty-folders`
  - Controller: `ApiTranscriptorController::emptyFolders()`
  - Cache 5 min (key: `transcriptor:empty_folders:max{max_dirs}`)
  - Cap configurable (default 200 dirs por storage)
  - Escanea 2 niveles bajo base_path, helper `dirHasNoFiles()` con cap 200 items

- **Ruta**: `routes/web.php:198` (registrada)

- **Frontend**: `resources/views/ia/api-transcriptor/index.blade.php`
  - Banner amber en tab Storages, aparece solo si `total_missing_folders > 0`
  - Alpine state: `emptyFolders`, `emptyFoldersExpanded`
  - Acciones: `loadEmptyFolders()` al init, `skipStorage(item)` (reusa `toggleStorage`)
  - Botones por storage: "Re-escanear" (re-llama processFolder) + "Omitir storage" (PUT `transcription_enabled:false`)

### Resultado verificado

```
Storages con carpetas vacias: 6
Total carpetas vacias: 10
  sp=47  01 Emisoras 01:    1 (ANTENA2)
  sp=49  02 Emisoras 01 Reg: 2 (Antioquia/Radio_Red, Atlantico/Libertad)
  sp=53  05 Emisoras 04:    1 (Boyaca/San_Miguel)
  sp=55  06 Emisoras 05:    1 (Huila/Alerta_Neiva)
  sp=134 04 Emisoras 03:    4 (Bolivar/Stereo_R_Cartagena, Choco, Santa_Martha, Santander/41_Emisora_JAG)
  sp=185 42 CNC Medellin:   1 (11082026)
```

### Nota sobre falso negativo

sp=47 también tiene COLMUNDO/10082026, COLMUNDO/11082026, RED/10082026,
RED/11082026 vacías (vistas en el discovery original). El escaneo de
filesystem las cuenta como "no vacías" porque tienen archivos antiguos
de julio. Si quieres precisión al día, el helper `dirHasNoFiles()`
aceptaría un parámetro `max_age_seconds` — pendiente si surge.

### Cambios en código

1. `app/app/Services/Ia/TranscriptionPollingService.php:39-46`
   - Añadido `->orderBy('id', 'DESC')` antes del `->limit()`
   - Comentario explicativo del porqué

### Cambios en datos

2. SQL ejecutado: `UPDATE transcriptions SET state='dead', error_message='Job perdido tras reinicio del transcriptor (404 not_found)...', finished_at=NOW() WHERE state='queued' AND id <= 92553`
   - 832 jobs marcados como dead
   - cutoff confirmado con binary search contra la API

### Verificación

| Métrica | Antes | Después |
|---|---:|---:|
| `done` count | 88,514 | 89,453 (+939) |
| `queued` count | 85,635 | 83,990 |
| `dead` count | 4,222 | 5,054 |
| Poll iter 1 | 0 done / 140 pending | 136 done / 4 pending |
| Poll iter 2 | 0 done / 140 pending | 140 done / 0 pending |
| Poll iter 3 | 0 done / 140 pending | 140 done / 0 pending |
| Último `done` | 2026-08-03 08:54 | 2026-08-11 14:25 |

El sistema ahora drena ~140 transcripciones/minuto. Para limpiar los
~84k restantes tomará ~10 horas al ritmo actual. Considerar bajar
`poll_limit` no ayuda (y subirlo puede saturar la API), pero sí
desactivar temporalmente las emisoras que producen 0-byte files (las
9 carpetas vacías identificadas arriba) bajaría la carga.
