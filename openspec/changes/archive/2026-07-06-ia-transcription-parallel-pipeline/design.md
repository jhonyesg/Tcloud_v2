## Context

El módulo de transcripción de TCloud (`ia-transcription-module`, 79/90 tareas completadas) está operativo: envía archivos a la API externa (`192.168.0.138:9000`), recibe webhooks, guarda SRTs, genera alertas por keywords. Sin embargo, el procesamiento es secuencial (1 archivo a la vez), no separa automático de manual, y tiene un bug de jobs colgados.

El servidor TCloud tiene 40 cores, 20GB de RAM disponible en tmpfs (`/dev/shm`), y la API externa tiene 2 workers GPU. El cuello de botella real es la GPU, pero TCloud desperdicia 39 cores en la fase de conversión ffmpeg.

## Goals / Non-Goals

**Goals:**
- 10 workers ffmpeg paralelos procesando la cola Redis
- scan-new solo procesa HOY (no histórico)
- Procesamiento manual selectivo: carpeta, día, o lote global
- Alertas opcionales en procesamiento manual (checkbox, default OFF)
- Conversión en RAM (tmpfs /dev/shm)
- Recuperación automática de jobs colgados
- Lote en background (cerrar/recargar no lo detiene)

**Non-Goals:**
- Multi-nodo del transcriptor (D7 original: YAGNI, 1 nodo)
- UI de monitoring por worker individual
- Procesamiento automático de histórico

## Decisions

### D1: Queue workers + supervisor vs paralelismo ad-hoc
**Decisión:** 10 queue workers Laravel via supervisor procesando Redis.
**Alternativas:** (a) fork de procesos PHP con exec(); (b) parallel ext / pcntl; (c) shell xargs -P 10.
**Razón:** Queue workers son nativos de Laravel, con tracking, reintentos automáticos (si un worker muere, el job vuelve a la cola tras timeout), y sin procesos zombies. Supervisor los mantiene vivos. El problema anterior (no había workers) se resuelve con supervisor, no evitándolos.

### D2: Prioridad de jobs en cola
**Decisión:** `priority = storage_priority * 10 + (es_hoy ? 100 : 0) + (es_manual ? 5 : 0)`.
**Alternativas:** (a) solo storage_priority; (b) FIFO puro; (c) cola separada por origen.
**Razón:** Hoy debe procesarse antes que histórico (alertas en tiempo real). Storage priority da preferencia a canales de TV sobre radio. Manual tiene ligero bonus sobre auto (el usuario está esperando). Redis ordena por priority DESC + created_at ASC automáticamente.

### D3: scan-new solo HOY
**Decisión:** scan-new filtra `file_modified_at >= today 00:00`.
**Alternativas:** (a) window configurable (últimas N horas); (b) sin filtro (comportamiento actual).
**Razón:** Si se habilita un storage con 30 días de histórico, el auto no debe procesar archivos viejos (alertas irrelevantes). El histórico es manual y selectivo. El filtro por "hoy" es intuitivo y coincide con el ciclo de grabación (el grabador escribe en carpetas `dmY`).

### D4: Alertas opcionales por origen
**Decisión:** scan-new siempre genera alertas; manual tiene checkbox "Generar alertas" (default OFF).
**Alternativas:** (a) alertas por timestamp (automático si file_modified_at > hoy); (b) alertas siempre; (c) alertas nunca en manual.
**Razón:** El auto procesa lo de hoy → alertas son el valor del módulo. El manual puede ser histórico (alertas inútiles) o re-procesamiento de hoy (alertas duplicadas). El usuario decide. Default OFF evita spam accidental de alertas viejas. Campo `generate_alerts` en `transcriptions` persiste la decisión.

### D5: Estado `pending` para detectar colgados
**Decisión:** Transcription se crea con `state=pending` (no `queued`) hasta que la API externa acepta el job y devuelve `job_id`. En ese momento pasa a `queued`.
**Alternativas:** (a) timestamp `queued_at` vs `submitted_at`; (b) no crear Transcription hasta tener job_id.
**Razón:** `pending` es explícito y consultable. scan-stale recupera `pending` sin `job_id` con >5 min de antigüedad (reencola). Con queue workers, si un worker muere, el job vuelve a Redis tras timeout y al reejecutarse ve la Transcription `pending` y la reutiliza (firstOrCreate). Sin `pending`, un job colgado queda invisible.

### D6: Conversión en RAM (tmpfs)
**Decisión:** Archivos Opus temporales y JSON de progreso en `/dev/shm` con fallback a `sys_get_temp_dir()`.
**Alternativas:** (a) disco (comportamiento anterior); (b) RAM disk dedicado montado aparte.
**Razón:** `/dev/shm` ya existe con 20GB, los Opus son ~7MB cada uno, y se borran tras el envío. Fallback automático si tmpfs no disponible. Reduce escrituras en disco (vida útil).

### D7: Selección granular — carpeta y día
**Decisión:** Botones "Procesar carpeta" y "Procesar día" en el navegador de archivos. Encola jobs para los archivos visibles con `dispatch()`.
**Alternativas:** (a) solo lote global; (b) UI de selección individual checkbox por checkbox.
**Razón:** El usuario navega carpetas por día (`dmY`). "Procesar día" = procesar la carpeta activa. "Procesar carpeta" = procesar la carpeta actual del breadcrumb. Ambos encolan con prioridad manual. Simple y alineado con el flujo de navegación existente.

## Data Model

```
transcriptions (modified)
  ...campos existentes...
  state             varchar(20)            -- pending|queued|processing|done|error|dead  (NUEVO: pending)
  generate_alerts   boolean default true   -- NUEVO: si false, omite KeywordMatcher
```

## Architecture — flujo con workers paralelos

```
┌──────────────────────────────────────────────────────────────────────┐
│  GENERADORES DE JOBS                                                  │
│                                                                       │
│  scan-new (auto, cada 2 min)          process-batch (manual, bg)     │
│    solo HOY                             lote global, distribuido      │
│    batch 5                              por prioridad de storage       │
│    alertas=siempre                      alertas=checkbox (default OFF) │
│    dispatch(priority=auto+hoy)          dispatch(priority=manual)     │
│                                                                       │
│  process-folder (manual)              process-day (manual)            │
│    carpeta actual del breadcrumb        archivos visibles HOY/AYER     │
│    alertas=checkbox                     alertas=checkbox               │
│    dispatch(priority=manual)            dispatch(priority=manual)     │
└───────────────────────────┬───────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────────┐
│  COLA REDIS con prioridad                                             │
│                                                                       │
│  priority = storage_priority * 10 + (hoy ? 100 : 0) + (manual ? 5 : 0)│
│  Orden: priority DESC, created_at ASC                                 │
│                                                                       │
│  [P=150] caracol hoy manual (storage P=10, hoy, manual)              │
│  [P=110] caracol hoy auto    (storage P=10, hoy)                      │
│  [P=100] radio hoy auto     (storage P=0, hoy)                        │
│  [P=50]  caracol historico   (storage P=10, manual)                  │
│  [P=5]   radio historico     (storage P=0, manual)                   │
└───────────────────────────┬───────────────────────────────────────────┘
                            │
         ┌──────────────────┼──────────────────┐
         ▼                  ▼                  ▼
    ┌─────────┐       ┌─────────┐        ┌─────────┐
    │Worker 1 │       │Worker 5 │  ...   │Worker 10│
    │ ffmpeg  │       │ ffmpeg  │        │ ffmpeg  │
    │ /dev/shm│       │ /dev/shm│        │ /dev/shm│
    │ POST API│       │ POST API│        │ POST API│
    └─────────┘       └─────────┘        └─────────┘
         │                  │                  │
         └──────────────────┼──────────────────┘
                            ▼
                     ┌────────────┐
                     │ API externa│
                     │ 2 workers  │
                     │ GPU        │
                     └────────────┘
                            │
                            ▼ (webhook o polling)
┌──────────────────────────────────────────────────────────────────────┐
│  TranscriptionProcessor                                               │
│                                                                       │
│  if transcription.generate_alerts:                                    │
│    KeywordMatcher::run()  →  AlertDispatcher::send()                  │
│  else:                                                                │
│    skip matching (solo guarda SRT + segmentos)                        │
└──────────────────────────────────────────────────────────────────────┘
```

## Architecture — recuperación de colgados

```
┌──────────────────────────────────────────────────────────────────────┐
│  CICLO DE VIDA CON ESTADO pending                                     │
│                                                                       │
│  dispatch(job)                                                        │
│      │                                                                │
│      ▼                                                                │
│  Worker toma el job de Redis                                          │
│      │                                                                │
│      ▼                                                                │
│  Transcription::firstOrCreate(state=pending, job_id=null)             │
│      │                                                                │
│      ├── ffmpeg convierte en /dev/shm                                 │
│      │                                                                │
│      ├── POST /v1/transcribe                                          │
│      │       │                                                        │
│      │       ├── 200 OK → state=queued, job_id=xxx  ✓                 │
│      │       └── error  → state=error                              ✗  │
│      │                                                                │
│      ▼                                                                │
│  Job termina (worker libera, borra .opus de /dev/shm)                │
│                                                                       │
│  ── SI EL WORKER MUERE AQUÍ ──                                        │
│  Redis: job vuelve a la cola tras timeout (90s default)              │
│  Worker nuevo lo retoma:                                              │
│    Transcription::firstOrCreate → ya existe con state=pending         │
│    → reutiliza, continúa el flujo                                     │
│                                                                       │
│  ── SI NADIE LO RETOMA (worker caído permanentemente) ──             │
│  scan-stale (cada 5 min):                                             │
│    SELECT * FROM transcriptions                                       │
│    WHERE state = 'pending' AND job_id IS NULL                         │
│    AND created_at < NOW() - 5 min                                     │
│    → dispatch nuevo job (el firstOrCreate reutiliza la fila)         │
└──────────────────────────────────────────────────────────────────────┘
```

## Supervisor configuration

```ini
[program:tcloud-transcription-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/artisan queue:work redis --sleep=1 --tries=1 --timeout=120 --max-jobs=100
numprocs=10
autostart=true
autorestart=true
user=www
redirect_stderr=true
stdout_logfile=/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/worker.log
stopwaitsecs=130
```

`--max-jobs=100`: cada worker se reinicia tras 100 jobs (libera memoria de ffmpeg).
`--timeout=120`: si un job excede 120s, Laravel lo marca como failed y el worker continúa.
`--tries=1`: no reintentar automáticamente (el scan-stale recupera).
`--sleep=1`: si no hay jobs, esperar 1s antes de revisar otra vez.

## Priority formula examples

```
Storage "Caracol TV" priority=10, archivo de hoy, enviado manual:
  priority = 10*10 + 100 + 5 = 205

Storage "Caracol TV" priority=10, archivo de hoy, scan-new auto:
  priority = 10*10 + 100 + 0 = 200

Storage "Radio Valle" priority=0, archivo de hoy, scan-new auto:
  priority = 0*10 + 100 + 0 = 100

Storage "Caracol TV" priority=10, archivo histórico (hace 5 días), manual:
  priority = 10*10 + 0 + 5 = 105

Storage "Radio Valle" priority=0, archivo histórico, manual:
  priority = 0*10 + 0 + 5 = 5

Orden de procesamiento:
  205 → 200 → 100 → 105 → 5
  (Caracol hoy manual > Caracol hoy auto > Radio hoy auto > Caracol histórico > Radio histórico)
```

## Risks / Trade-offs

- **[Risk] 10 workers ffmpeg saturan CPU** → Mitigación: ffmpeg de audio usa ~1 core. 10 workers = 25% de 40 cores. Margen amplio. Si se observa saturación, reducir a 5.
- **[Risk] Redis llena memoria con cola grande** → Mitigación: Redis en modo persistence (AOF). Jobs pequeños (~100 bytes cada uno). 200 jobs en cola = ~20KB. No es problema.
- **[Risk] API externa rechaza por saturación de cola** → Mitigación: la API tiene su propia cola (queued state). Si enviamos 200 de golpe, la API los encola. SRT retention es 7 días, suficiente.
- **[Risk] Doble envío si scan-stale reencola un job que ya está siendo procesado** → Mitigación: `Transcription::firstOrCreate` por `file_id` unique. Si el job original termina primero, el reencolado ve `state != pending` y no hace nada.
- **[Risk] supervisor no instalado** → Mitigación: documentar instalación. Alternativa: script bash con nohup + loop de reinicio.
- **[Risk] tmpfs se llena si ffmpeg falla y no borra** → Mitigación: el job tiene `finally { unlink }`. Añadir cron de limpieza de /dev/shm/tcloud-transcription/ con archivos >1h.
- **[Risk] Alertas perdidas si generate_alerts=false por error** → Mitigación: default true en migración. Checkbox default OFF solo en UI de lote manual. scan-new siempre true.

## Migration Plan

1. **Migración**: añadir `generate_alerts` boolean default true a `transcriptions`
2. **Actualizar `ConvertAndTranscribeJob`**: aceptar `generateAlerts` bool, usar `dispatch()` con priority, tmpfs
3. **Actualizar `ScanNewRecordingsCommand`**: filtrar solo HOY, usar `dispatch()`
4. **Actualizar `ScanStaleJobsCommand`**: añadir recuperación de `pending` sin `job_id`
5. **Actualizar `AudioConverter`**: tmpfs (ya hecho parcialmente)
6. **Actualizar `TranscriptionProcessor`**: respetar `generate_alerts`
7. **Instalar supervisor** + configurar 10 workers
8. **Frontend**: botones carpeta/día, checkbox alertas en lote
9. **Smoke test**: lote de 50 archivos con 10 workers, verificar paralelismo
10. **Rollback**: si workers inestables, volver a ejecución síncrona (reflection)