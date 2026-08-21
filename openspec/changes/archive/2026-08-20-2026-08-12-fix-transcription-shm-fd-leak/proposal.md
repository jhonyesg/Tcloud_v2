# Change: Fuga de file descriptors a /dev/shm paraliza la transcripción

## Why

El módulo de transcripción dejó de enviar trabajos al API externo de forma silenciosa
a partir del 2026-08-12 ~09:22, después de que un dispatch manual de ~600 jobs
llenara `/dev/shm` (tmpfs 40GB) al 100%. Desde entonces **todos los jobs fallan en
~250 ms sin llegar al transcriptor**, aunque la cola Redis siga "encolando" nuevos
trabajos normalmente.

**Medición en producción (2026-08-12 09:35 -05):**

| Métrica | Valor |
|---|---|
| Workers `queue:work` vivos | 12 (15h36m de uptime cada uno) |
| Cola Redis `tcloud_queues:transcription` | 174 jobs |
| `tcloud_queues:transcription:reserved` | 12 jobs |
| `/dev/shm` uso | 40 GB de 40 GB (100%) |
| `Shmem` (meminfo) | 38.5 GB |
| Fds por worker a `/dev/shm/tcloud-transcription/*.wav (deleted)` | 94 – 109 |
| Errores `ffmpeg falló: Could not seek to 1026: Invalid argument` | 3 939 |
| Errores `Permission denied` al escribir `laravel.log` | 5 357 |
| Transcripciones `pending` en BD | 101 (incluye archivos de hoy desde las 00:28) |
| Jobs que llegan al API externo | **0 desde el spike de las 09:22** |

**Causa raíz.** En `TranscriptorApiClient::submitRequest()`
(`app/app/Services/Ia/TranscriptorApiClient.php:83`) el multipart se construye con
`fopen($audioPath, 'r')` y se pasa a `->attach(...)` sin un cierre explícito del
resource. Combinado con la política de reintentos de `submit_max_attempts`, cada
intento abre un fd nuevo y los anteriores no se cierran limpiamente en todas las
rutas (timeout, 5xx, ConnectionException, throw: false). Como los workers son
procesos de larga vida (`queue:work --queue=transcription --tries=3` corre durante
horas), los fds se acumulan:

1. Cada fd abierto apunta a un `.wav` ya `unlink()`-eado por el `finally` de
   `TranscriptionSubmitService.php:104-108`. El inode sigue vivo en tmpfs mientras
   haya algún fd apuntándolo.
2. tmpfs libera la RAM **solo cuando el último fd se cierra**, no cuando se hace
   `unlink()`. Por eso `ls /dev/shm/tcloud-transcription/` aparece vacío pero el
   `df` marca 100%.
3. Cuando `/dev/shm` se llena, `ffmpeg` no puede escribir el WAV intermedio y
   aborta inmediatamente con `EINVAL` (el error "Could not seek to 1026" es la
   syscall `pwrite`/`ftruncate` fallando por ENOSPC).
4. El job falla antes de la llamada HTTP, así que el API nunca recibe nada.

El operador ve "llego un lote pero después nada" porque el batch manual de las 09:22
fue lo que disparó la fuga hasta el tope; los jobs previos al pico sí llegaron al
API (sus WAVs se cerraron correctamente), los posteriores no.

**Por qué no se notó antes.** La fuga es gradual y silenciosa: con ~10 jobs/min
durante horas los fds se acumulan despacio, y tmpfs aguanta hasta que un dispatch
masivo (o el paso del tiempo) lo lleva al límite. Cuando llega a 100%, el sistema
**no se recupera solo** porque cada intento de transcripción es un fd nuevo, así
que sigue empeorando.

## What Changes

### 1. Fix del fd leak en `TranscriptorApiClient`

- `submitNoCallback()` envuelve la llamada HTTP en `try/finally` que llama
  `fclose()` explícitamente sobre el resource abierto con `fopen()` antes de
  retornar (éxito, error, o reintento agotado).
- Se elimina el `fopen()` del helper `submitRequest()`: el resource se abre
  **dentro del closure de retry** para que cada intento tenga un ciclo de vida
  propio, y se garantiza `fclose` con `try/finally` alrededor del `->send()`.
- Si `$audioPath` no es legible al momento de abrir, el job termina sin abrir fd.

### 2. Pre-flight de espacio en `/dev/shm`

Antes de invocar `AudioConverter::convert()`, `TranscriptionSubmitService::submit()`
consulta el espacio libre de `/dev/shm/tcloud-transcription/`:

- Si hay menos de `min_shm_free_bytes` (default 200 MB, configurable), NO se intenta
  convertir. El job se marca `error` con mensaje claro y se reintenta en el próximo
  ciclo del tick (la lógica de `--tries=3` ya cubre los reintentos a nivel de job).
- Si `/dev/shm` no es escribible, fallback automático a `sys_get_temp_dir()`
  (comportamiento actual, ahora promovido a decisión explícita y logueada).

### 3. Cleanup de huérfanos en `/dev/shm/tcloud-transcription/`

- Comando nuevo `transcription:cleanup-orphan-wav` que:
  - Lista archivos `.wav`/`.opus` con más de N minutos de antigüedad (default 30 min,
    configurable) en `/dev/shm/tcloud-transcription/` y los elimina si **ningún
    proceso** los tiene abiertos (verificación con `lsof +D --exit` o equivalente).
  - NO elimina archivos cuyo mtime sea reciente (job en curso) ni los que tengan
    fd abierto (eso es síntoma del bug que el fix #1 ataca, no se "tapa" aquí).
- Se agenda en `routes/console.php` cada 15 min. Es defensivo: cuando el bug #1
  esté corregido no debería encontrar nada que limpiar.

### 4. Monitoreo básico en UI/API

- Nuevo método `transcription:shm-status` (o equivalente en `ApiTranscriptorController`)
  que devuelve `{total, used, free, percent, dir_writable, fds_per_worker}`.
- Se loguea **WARNING** cuando `/dev/shm` supera el 80% (configurable). Las
  advertencias estructuradas permiten detectarlo desde Laravel scheduler sin
  construir UI nueva.
- Tarea de scheduler nueva `transcription:check-shm-health` cada 10 min que
  escribe el estado a `Cache::put('transcriptor:shm:status', ..., 600)` y loguea
  si supera el umbral.

### 5. Mitigación inmediata (operacional, NO requiere código nuevo)

Se documenta en `tasks.md` como **Task 0** para ejecutarse **antes** del deploy:

```bash
# 1. Pausar workers (evita más daño mientras se libera espacio)
sudo supervisorctl stop tcloud-transcription-workers    # si existe

# 2. Liberar tmpfs (umount + mount recrea el FS; los wavs en uso se invalidan)
sudo mount -o remount,size=40G /dev/shm                 # si está en fstab
# o, como lot:
sudo umount /dev/shm && sudo mount -t tmpfs -o size=40G tmpfs /dev/shm

# 3. Confirmar espacio
df -h /dev/shm                                           # debe mostrar > 5 GB libre

# 4. Levantar workers
sudo supervisorctl start tcloud-transcription-workers
```

Si los workers se levantan sin el fix #1 aplicado, la fuga vuelve a reproducirse en
minutos; por eso el fix de raíz es obligatorio antes de reabrir tráfico.

## Scope

- **Afecta:** `app/app/Services/Ia/TranscriptorApiClient.php`,
  `app/app/Services/Ia/TranscriptionSubmitService.php`,
  `app/app/Console/Commands/Transcription*`,
  `app/routes/console.php`,
  `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (solo endpoint shm-status).
- **No afecta:** el módulo de archivos, comparticiones, UI de Correcciones,
  pipeline de grabación (los `ffmpeg` del grabador usan sus propios tmpfs output
  paths y no tocan `/dev/shm/tcloud-transcription/`).
- **No requiere migración** de BD.
- **Sí requiere** despliegue coordinado: aplicar Task 0 antes de subir el código,
  sino la mitigación operativa se queda sin efecto.

## Non-Goals

- **No** se cambia la elección de `/dev/shm` como destino de los WAV. tmpfs es
  correcto para rendimiento; el problema es la falta de cleanup, no el medio.
- **No** se aumenta el tamaño de `/dev/shm`. Con 40 GB hay espacio de sobra si los
  fds no se fugan. Subir el techo solo retrasa el síntoma.
- **No** se sustituye `Symfony\Process` por un wrapper de streams custom. El fix
  en `TranscriptorApiClient` es suficiente; reescribir el wrapper es scope creep.
- **No** se introduce circuit breaker contra el API de transcripción. Si el API
  está caído, los reintentos existentes (`submit_max_attempts`) son suficientes.
- **No** se modifica `ConvertAndTranscribeJob` ni la lógica del tick. El problema
  está aguas abajo del job, en el submit HTTP.

## Risk

- **Bajo** en el fix #1 (cerrar fds explícitamente es estrictamente mejor que el
  estado actual).
- **Medio** en el pre-flight #2: si el umbral está mal calibrado, jobs válidos
  pueden rebotar. Por eso el default (200 MB) es conservador y es configurable
  por storage si la calibración fina lo pide.
- **Bajo** en el cleanup #3: solo borra archivos viejos sin fd abierto. Una
  condición de carrera donde un proceso intente abrir justo cuando se borra es
  teóricamente posible pero prácticamente irrelevante (los WAVs viejos ya no son
  referenciables: el `unlink` original los borró hace minutos).
- **Mitigación operativa (Task 0)** es **disruptiva**: pausar workers interrumpe
  la cola por ~1-2 min. Es aceptable dado el estado actual (la cola ya está
  paralizada).
