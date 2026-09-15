## Purpose

Redefinir las señales que utiliza el regulador del tick de transcripción para frenar el despacho, sustituyendo la medición de profundidad de cola Redis local (que no refleja carga real de GPU ni de la vía upstream) por una combinación configurable de señales honestas: porcentaje de uso de la API externa, espacio libre en `/dev/shm`, número de jobs concurrentes dentro del semáforo de inflight, y como respaldo la profundidad de la cola Redis.

## Requirements

### Requirement: Modos del regulador configurables en caliente
El sistema SHALL soportar tres modos seleccionables desde la UI de settings y desde la variable de entorno `TRANSCRIPTOR_REGULATOR_MODE`:
- `local_only` (default inicial): conserva el comportamiento actual basado en `target_redis_queue`; solo activa además la guarda de `/dev/shm` libre.
- `remote_aware`: además de `local_only`, consulta `GET /api/stats` en la API externa con caché de N segundos (`TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS`, default 15) y frena si `processing_jobs / remote_capacity >= TRANSCRIPTOR_REGULATOR_REMOTE_SATURATION_PCT` (default 80).
- `hybrid`: dispara freno si CUALQUIERA de las señales configuradas reporta saturación: `redis_queue_depth >= target_redis_queue`, `remote_gpu_usage >= umbral`, `shm_free_bytes < min_shm_free_bytes`, `inflight_active >= inflight_max`, o `dispatch_paused=true`.

#### Scenario: Modo `local_only` con cola llena y GPU remota al 30%
- **WHEN** `regulator_mode=local_only`, `Redis::llen('queues:transcription')=140` (>= target) y `processing_jobs` remoto < 50% de `remote_capacity`
- **THEN** el tick frena por `queue_at_target` aunque la GPU esté ociosa
- **AND** el endpoint `/regulator-cause` reporta `decision=skipped`, `reason=queue_at_target`, `values.remote_gpu_usage=null`

#### Scenario: Modo `remote_aware` con cola al 90% pero GPU al 25%
- **WHEN** `regulator_mode=remote_aware`, cola local al 90% del target y la API remota reporta procesamiento al 25%
- **THEN** el tick encola con el batch calculado (no frena por cola)
- **AND** el endpoint reporta `decision=dispatched` con `remote_gpu_usage=25%`

#### Scenario: Modo `hybrid` con cualquier señal saturada
- **WHEN** `regulator_mode=hybrid` y DOS o más señales indican saturación simultáneas (cola llena + GPU >80%)
- **THEN** el tick registra la señal que PRIMERO disparó (`signals_evaluated` en orden) y frena
- **AND** la respuesta indica cuál fue la causa dominante y cuáles eran secundarias

### Requirement: Señal de RAM host remota (`remote_ram_pressure`)
El sistema SHALL evaluar el porcentaje de RAM del host del API upstream en cada tick cuando `regulator_mode` sea `remote_aware` o `hybrid`, leyéndolo desde `/api/metrics/overview::ram.pct` (vía `getRemoteInfo()`, cacheado con `regulator_remote_cache_seconds`).

Cuando `ram_pct >= remote_ram_pressure_pct` (default 90%): `decision=skipped`, `reason=remote_ram_pressure`, `batch_computed=0`. RAM alta mata el contenedor del ASR antes que cualquier otra señal.

#### Scenario: RAM del host al 93%
- **WHEN** `/api/metrics/overview` devuelve `ram.pct=93` y `remote_ram_pressure_pct=90`
- **THEN** el tick frena con `decision=skipped, reason=remote_ram_pressure`, sin importar el estado de la cola Redis local

#### Scenario: RAM sana, cola llena
- **WHEN** `ram.pct=46` (bajo umbral) y `Redis::llen >= target_redis_queue`
- **THEN** el freno es por `queue_at_target`; `remote_ram_pressure` no dispara

### Requirement: Señal de ramdisk remoto (`remote_ramdisk_pressure`)
El sistema SHALL evaluar el porcentaje de uso del ramdisk del API upstream (`/api/metrics/overview::ramdisk.pct`) en cada tick cuando `regulator_mode` sea `remote_aware` o `hybrid`. El ramdisk es donde el ASR escribe los WAV intermedios.

Cuando `ramdisk_pct >= remote_ramdisk_pressure_pct` (default 85%): `decision=skipped`, `reason=remote_ramdisk_pressure`, `value=ramdisk_pct`, `batch_computed=0`.

Prioridad de evaluación de frenos (primer match gana):
1. `upstream_circuit_open`
2. `remote_ram_pressure`
3. `remote_ramdisk_pressure`
4. `remote_queue_full`
5. `redis_queue_depth` (`queue_at_target`)
6. `shm_free_bytes` (`shm_low`)
7. `inflight_full`

`gpu.util_pct` NO SHALL evaluarse como freno: GPU al 100% es señal de trabajo en curso, no de saturación. Las señales reales de salud del nodo ASR son RAM (mata el contenedor), ramdisk (llena `/mnt/ramdisk`) y cola remota (trabajo ya pendiente).

#### Scenario: ramdisk al 91% con GPU al 95%
- **WHEN** `/api/metrics/overview` devuelve `ramdisk.pct=91.08` y `remote_ramdisk_pressure_pct=85`
- **THEN** `decision=skipped, reason=remote_ramdisk_pressure, value=91.08, batch_computed=0`

#### Scenario: ramdisk normal, resto sano
- **WHEN** `ramdisk.pct=42` y las demás señales bajo umbral
- **THEN** el regulador no frena por ramdisk y evalúa las señales restantes

### Requirement: Señal de cola remota llena (`remote_queue_full`) y batch por rampa
El sistema SHALL leer `queue.by_state_corrected["queued/0"]` del upstream (`queue_queued`) y usarlo como la señal principal de carga real del cluster:

- **Freno**: si `queue_queued >= target_remote_queue` (default 180), `decision=skipped, reason=remote_queue_full`.
- **Batch por rampa** (`computeEffectiveBatch`): entre `floor_remote_queue` (default 30) y `target_remote_queue` el batch baja linealmente; a `queue_queued <= floor` envía un pulso completo de `pulse_batch_size` (default 50). El resultado se corta además por el deficit Redis local (`computeDispatchBatch`), por `max_batch` estático, y baja con el `stuck_multiplier` (`1 - min(0.8, stuck_count * stuck_penalty_pct / 100)`) donde `stuck_count` son filas `queued` con `started_at` más viejas que `remote_wait_warn_seconds`.

#### Scenario: cola remota en piso → pulso completo
- **WHEN** `queue_queued=10 <= floor_remote_queue=30`
- **THEN** el batch candidato es `pulse_batch_size` (sujeto a deficit local, `max_batch` y stuck penalty)

#### Scenario: cola remota sobre target → freno
- **WHEN** `queue_queued=200 >= target_remote_queue=180`
- **THEN** `decision=skipped, reason=remote_queue_full, batch_computed=0`

#### Scenario: rampa lineal en zona media
- **WHEN** `queue_queued=105`, floor=30, target=180, pulse=50
- **THEN** el candidato es `round((180-105)/(180-30) * 50) = 25`, recortado después por deficit/max_batch/stuck

### Requirement: Telemetría remota vía `/api/metrics/overview`
`TranscriptorApiClient::getRemoteInfo()` SHALL consultar `GET {base_url}/api/metrics/overview` (endpoint público, plano, sin auth) con timeout estricto (`regulator_remote_timeout_ms`, default 800) y devolver un payload normalizado que incluye: `workers`, `processing`, `capacity`, `usage_pct`, `cluster_state`, `circuit_open`, `gpu_util_pct`, `gpu_vram_pct`, `gpu_model`, `ram_pct`, `ramdisk_pct`, `ramdisk_free_gb`, `cpu_pct`, `cpu_load_1m`, `queue_total`, `queue_queued`, `queue_done_minus1`, `source`, `fetched_at`. Si el endpoint no responde en plazo, devuelve `null` y el regulador corre fail-open (sin freno por señales remotas).

`getRemoteStats()` queda como wrapper backward-compatible que consume el mismo endpoint y devuelve `{processing, capacity, usage_pct}`.

#### Scenario: fetch exitoso
- **WHEN** la API responde <800ms con JSON plano
- **THEN** el regulador obtiene `workers=3, ram_pct=46.3, ramdisk_pct=0, queue_queued=0` (ejemplo real 2026-09-14) y evalúa todas las señales remotas

#### Scenario: endpoint caído
- **WHEN** `/api/metrics/overview` no responde o devuelve garbage
- **THEN** `getRemoteInfo()` devuelve `null`, el regulador NO frena por señales remotas, y el log registra el fallo

### Requirement: Ramp-up progresivo con stagger de chunks
El tick SHALL dividir el batch final en chunks de `stagger_chunk_size` (default 5) y encolarlos con una pausa de `dispatch_stagger_ms` (default 250) entre chunk y chunk, evitando arrancar N ffmpeg simultáneos en el mismo instante.

#### Scenario: batch 60 con chunk 5 y stagger 250ms
- **WHEN** el regulador computa `batch_computed=60`
- **THEN** el tick encola 12 chunks de 5 jobs, con 250ms entre cada chunk (verificado en producción 2026-09-14: `chunks=12, stagger_ms=250`)

### Requirement: Señal de GPU remota con caché y timeout estricto
El sistema SHALL consultar `GET /api/stats` de la API externa con un timeout estricto (`TRANSCRIPTOR_REGULATOR_REMOTE_TIMEOUT_MS`, default 800) y caché en Redis bajo clave `transcriptor:remote_stats` con TTL configurable (`TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS`, default 15).

#### Scenario: API externa responde lento
- **WHEN** el endpoint remoto tarda >800ms en responder
- **THEN** el regulador considera la señal como "unknown" y NO dispara freno basado en GPU (fail-open conservador)
- **AND** registra `warn` con la duración observada para diagnóstico

#### Scenario: Cache caliente
- **WHEN** el último fetch exitoso de `/api/stats` tiene TTL < 15s
- **THEN** el regulador reusa el valor cacheado sin pegar al upstream
- **AND** el log indica `remote_stats_cache_hit=true`

#### Scenario: Cache fría y upstream disponible
- **WHEN** el cache está vacío y la API responde <800ms con JSON válido
- **THEN** el regulador persiste el resultado en Redis con TTL=15s
- **AND** extrae `processing` y `capacity` (o nombres equivalentes del API) para el cálculo

### Requirement: Señal de /dev/shm reutiliza el helper existente
El sistema SHALL reusar `disk_free_space()` sobre `/dev/shm` que ya se evalúa en `TranscriptionSubmitService` y SHALL exponer el valor en la decisión del regulador para que `reason=shm_low` quede registrado en logs cuando dispare freno.

#### Scenario: `/dev/shm` por debajo de `min_shm_free_bytes`
- **WHEN** `disk_free_space('/dev/shm') < min_shm_free_bytes` (default 200MB)
- **AND** `regulator_mode` es `local_only` o `hybrid`
- **THEN** el tick frena con `reason=shm_low`
- **AND** no encola hasta el próximo ciclo

#### Scenario: `/dev/shm` sano pero cola llena
- **WHEN** `/dev/shm` tiene espacio de sobra pero `Redis::llen >= target_redis_queue`
- **THEN** el freno es por `queue_at_target` (no por `shm_low`)
- **AND** el endpoint lista ambas señales en `signals_evaluated` con sus valores

### Requirement: Señal de inflight cuenta jobs vivos
El sistema SHALL contar el número de jobs actualmente en la fase ffmpeg + POST usando `Cache::get('transcriptor:inflight:active')` (clave que ya mantiene `LimitTranscriptionConcurrency`).

#### Scenario: Inflight alcanza `inflight_max`
- **WHEN** `inflight_active >= inflight_max` y `inflight_max > 0`
- **THEN** el tick frena con `reason=inflight_full`
- **AND** al volver a `inflight_active < inflight_max`, el siguiente tick encola

#### Scenario: `inflight_max=0` desactiva la señal
- **WHEN** `inflight_max <= 0` (modo sin semáforo)
- **THEN** la señal `inflight_active` no aparece en `signals_evaluated`
- **AND** no dispara freno basado en inflight

### Requirement: Causa dominante persiste en `transcriptions.regulator_skip_reason`
El sistema SHALL escribir `regulator_skip_reason` con la razón dominante del freno en TODAS las filas pendientes del almacenamiento actual afectadas por el skip, en el mismo tick que decide omitir.

#### Scenario: Skip por GPU remota persiste causa
- **WHEN** el modo es `remote_aware` y la API remota reporta saturación
- **THEN** el tick recorre las `Transcription` pendientes sin `dispatched_at` del almacenamiento actual y actualiza `regulator_skip_reason='remote_gpu_saturated'`
- **AND** cuando el tick siguiente ya no frena, `regulator_skip_reason` queda con el último valor hasta que se encole y se popule `dispatched_at`

#### Scenario: Causa legible por el panel
- **WHEN** el admin abre el panel de diagnóstico
- **THEN** la causa dominante se traduce a un texto humano (mapeo fijo): `queue_at_target` → "Cola Redis en objetivo", `remote_gpu_saturated` → "GPU remota saturada", `shm_low` → "/dev/shm bajo de espacio", `inflight_full` → "Concurrencia ffmpeg+POST al máximo", `dispatch_paused` → "Despacho pausado manualmente"

### Requirement: Compatibilidad con `dispatch_paused`
El sistema SHALL mantener `dispatch_paused` como freno de emergencia prioritario: si está activo, ningún modo del regulador encola, independientemente del resto de señales.

#### Scenario: dispatch_paused tiene precedencia
- **WHEN** `dispatch_paused=true`, modo=`hybrid`, todas las demás señales sanas
- **THEN** el tick frena con `reason=dispatch_paused`
- **AND** los endpoints de envío de la UI siguen devolviendo HTTP 423 (sin cambios)

#### Scenario: dispatch_paused se desactiva
- **WHEN** `dispatch_paused=false` y el resto de señales sanas
- **THEN** el tick reanuda encolo normal en el siguiente ciclo
- **AND** el endpoint `/regulator-cause` muestra `decision=dispatched`

### Requirement: El cliente respeta `Retry-After` en 429 y 503
El sistema SHALL, ante respuesta HTTP 429 o 503 del upstream, leer el header `Retry-After` (entero o RFC-7231 HTTP-date), normalizarlo a segundos enteros, y propagarlo como `int $retryAfter` en `UpstreamRateLimitException` o `UpstreamUnavailableException` respectivamente. Si el header está ausente o mal formado en 503, SHALL usar `SystemSetting('max_backoff_seconds')` (default 300).

#### Scenario: 429 con Retry-After numérico
- **WHEN** `GET /v1/transcribe` responde `429` y `Retry-After: 12`
- **THEN** `TranscriptorApiClient` lanza `UpstreamRateLimitException($retryAfter=12)`
- **AND** `TranscriptionSubmitService::submit()` captura y llama `markRequeueable($tx, now()->addSeconds(12))`
- **AND** `last_run` del tick y la fila NO se marcan como `dead`

#### Scenario: 503 con Retry-After HTTP-date
- **WHEN** `503` con `Retry-After: Wed, 21 Oct 2026 07:28:00 GMT`
- **THEN** el sistema calcula diff con `now()` y propaga `UpstreamUnavailableException(294)` (por ejemplo)

### Requirement: Circuit breaker corta el tick tras strikes consecutivos
El sistema SHALL contar strikes upstream en Redis bajo `transcriptor:upstream:strikes:{minute}` (TTL 5 min) y abrir el break cuando `count >= SystemSetting('circuit_breaker_threshold')` (default 3) en una ventana móvil de 5 min. El break abierto SHALL persistir `circuit_breaker_open_until = now() + SystemSetting('circuit_breaker_open_seconds')` (default 60). Mientras el break esté abierto, el regulador SHALL evaluar `upstream_circuit_open` y frenar con `reason=upstream_circuit_open`, `batch_computed=0`.

#### Scenario: 3 strikes en 5 min abre el break
- **WHEN** 3 respuestas 4xx/5xx transitorias en menos de 5 min
- **THEN** `UpstreamCircuitBreaker::isOpen()` retorna true
- **AND** el siguiente tick sale con `decision=skipped, reason=upstream_circuit_open`
- **AND** `regulator_skip_reason='upstream_circuit_open'` se persiste en filas pendientes

#### Scenario: Tras 60 s sin strikes, half-open permite encolar
- **WHEN** `circuit_breaker_open_until < now()` y no hay strikes nuevos
- **THEN** el tick reanuda encolo normal
- **AND** si llega una nueva respuesta transitoria, vuelve a contar y reabre

### Requirement: El regulador expone causa upstream en endpoint
El sistema SHALL extender `GET /ia/api-transcriptor/regulator-cause` para incluir `signals_evaluated[]` la cadena `'upstream_circuit'` cuando el break esté abierto, y SHALL traducir el valor a texto humano en el panel de diagnóstico ("API externa no responde tras N strikes").

#### Scenario: Diagnóstico muestra estado del break
- **WHEN** el admin abre el panel de diagnóstico y el break está abierto
- **THEN** la sección "Causa actual" muestra "API externa no responde tras N strikes (próximo intento en Xs)"

#### Scenario: Sin break, la señal no aparece
- **WHEN** el break nunca se ha abierto desde el último reset
- **THEN** `signals_evaluated` no contiene `'upstream_circuit'`
- **AND** el endpoint no muestra la sección de texto humano de break

### Requirement: Configuración del break y backoff por env/system setting
El sistema SHALL leer las nuevas claves `max_backoff_seconds`, `circuit_breaker_threshold`, `circuit_breaker_open_seconds` desde la capa `TranscriptorSettings` (con fallback al env y a config) y SHALL NO requerir config nueva de supervisor ni reinicio del servicio.

#### Scenario: Ajuste sin redeploy
- **WHEN** el admin guarda `SystemSetting('circuit_breaker_threshold') = 5`
- **THEN** el siguiente tick usa el nuevo valor sin tocar nada más
- **AND** `php artisan tinker` confirmando lectura ve `'circuit_breaker_threshold' => '5'`

#### Scenario: Setting vacío o inválido no rompe el regulador
- **WHEN** un setting no existe o es no-numérico
- **THEN** `TranscriptorSettings::int('circuit_breaker_threshold')` devuelve el default `3`
- **AND** el regulador no lanza excepciones por configuración
