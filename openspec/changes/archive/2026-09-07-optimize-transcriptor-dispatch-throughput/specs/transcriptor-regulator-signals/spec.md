## Purpose

Redefinir las señales que utiliza el regulador del tick de transcripción para frenar el despacho, sustituyendo la medición de profundidad de cola Redis local (que no refleja carga real de GPU ni de la vía upstream) por una combinación configurable de señales honestas: porcentaje de uso de la API externa, espacio libre en `/dev/shm`, número de jobs concurrentes dentro del semáforo de inflight, y como respaldo la profundidad de la cola Redis.

## ADDED Requirements

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
