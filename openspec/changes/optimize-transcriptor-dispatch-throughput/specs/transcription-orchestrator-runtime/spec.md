# Delta — transcription-orchestrator-runtime

## MODIFIED Requirements

### Requirement: 3. Phase 2 — Regulator dispatch (current-day only)

- After Phase 1, the tick **MUST** decide whether to dispatch using the `regulator_mode` configured (`local_only` | `remote_aware` | `hybrid`, default `local_only`) via `TranscriptorSettings::str('regulator_mode')`.
- The decision tree **SHALL** be (in this priority order):
  1. If `dispatch_paused=true` → `decision=skipped`, `reason=dispatch_paused`, no tocar Redis.
  2. Compute each signal selected by `regulator_mode`:
     - `redis_queue_depth = (int) Redis::llen('queues:transcription')` (siempre disponible).
     - `remote_gpu_usage = remote_processing / remote_capacity` con cache `transcriptor:remote_stats` TTL `TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS` (default 15s), solo en `remote_aware` y `hybrid`.
     - `shm_free_bytes = (int) disk_free_space('/dev/shm')` cuando existe, en cualquier modo.
     - `inflight_active = (int) Cache::get('transcriptor:inflight:active', 0)` solo si `inflight_max > 0`, en `hybrid` (y opcional en `local_only`).
  3. If `regulator_mode == local_only`: aplica la formula historica `batch = max(min, min(max, target - current + runway))`; si `batch <= 0` → `decision=skipped`, `reason=queue_at_target`. Adicionalmente, si `shm_free_bytes < min_shm_free_bytes` → `reason=shm_low`.
  4. If `regulator_mode == remote_aware`: la formula anterior corre, pero el freno se evalua con `remote_gpu_usage >= TRANSCRIPTOR_REGULATOR_REMOTE_SATURATION_PCT` antes que la cola local. Si la API remota reporta saturacion → `reason=remote_gpu_saturated`.
  5. If `regulator_mode == hybrid`: dispara freno si CUALQUIERA de `redis_queue_depth >= target_redis_queue`, `remote_gpu_usage >= umbral`, `shm_free_bytes < min_shm_free_bytes`, `inflight_active >= inflight_max` se cumple. La razon reportada es la primera en orden de prioridad que se evaluo como saturada.
- Si el regulador computa `batch > 0` (sea del modo que sea), el tick **SHALL** dispatch `ConvertAndTranscribeJob` para hasta `batch` filas donde:
  ```
  state = 'pending' AND job_id IS NULL AND created_at >= now()->startOfDay()
    AND (requeue_after_at IS NULL OR requeue_after_at <= now())
  ORDER BY created_at ASC
  ```
  ordered oldest-first within the day for fairness. En el momento del `dispatch()`, el tick **SHALL** setear `dispatched_at = now()` y persistir en BD como parte de la misma operacion (antes o atomico con el encolado).
- Cuando el tick decide `skipped`, **SHALL** poblar `transcriptions.regulator_skip_reason` con la razon dominante en TODAS las filas pendientes del almacenamiento actual sin `dispatched_at`.
- El tick **SHALL** persistir el resultado del calculo del regulador (incluyendo `signals_evaluated`, `values`, `decision`, `reason`, `batch_computed`, `fired_at`) en una clave de cache `transcriptor:tick:last_decision` con TTL de 1h para que el endpoint `GET /ia/api-transcriptor/regulator-cause` lo sirva sin recomputar.
- The dispatch **MUST** use the existing `ConvertAndTranscribeJob::dispatch($fileId, true)` producer; no new job class is introduced.

#### Scenario: Cola en objetivo omite el despacho (modo local_only)
- **WHEN** `regulator_mode=local_only`, la longitud de `queues:transcription` alcanza `target_redis_queue`
- **THEN** el tick registra "queue at target, skip dispatch" y no encola nada
- **AND** el endpoint `/regulator-cause` reporta `decision=skipped`, `reason=queue_at_target`, `values.remote_gpu_usage=null`

#### Scenario: Modo remote_aware evita freno con GPU ociosa
- **WHEN** `regulator_mode=remote_aware`, la cola local esta al 90% del target PERO la API remota reporta `processing/capacity < 0.5`
- **THEN** el tick encola hasta el batch calculado
- **AND** `/regulator-cause` reporta `decision=dispatched` y `values.remote_gpu_usage` menor a 50%

#### Scenario: Modo hybrid frena por cualquiera
- **WHEN** `regulator_mode=hybrid` y DOS o mas senales indican saturacion simultaneas (cola llena + GPU>80%)
- **THEN** el tick registra la primera senal que disparo (en orden: redis > remote > shm > inflight)
- **AND** `/regulator-cause` lista tanto la razon dominante como las secundarias

#### Scenario: Despacho oldest-first acotado por el regulador
- **WHEN** hay 500 pendientes del día y el regulador computa batch=140
- **THEN** se despachan exactamente 140 ConvertAndTranscribeJob, los más viejos primero
- **AND** las 140 filas tienen `dispatched_at` poblado tras el ciclo

#### Scenario: Skip persiste `regulator_skip_reason`
- **WHEN** el tick decide `skipped` por `reason=remote_gpu_saturated`
- **THEN** todas las filas `pending` del almacenamiento actual sin `dispatched_at` reciben `regulator_skip_reason='remote_gpu_saturated'`
- **AND** al volver a encolar en un tick posterior, el valor no se borra hasta que la fila obtenga `dispatched_at`

## ADDED Requirements

### Requirement: 14. Persistencia de la decision del regulador en cache
- El tick **SHALL** escribir la estructura `{fired_at, signals_evaluated, values, decision, reason, batch_computed}` en `Cache::put('transcriptor:tick:last_decision', ...) ` con TTL de 1h.
- Si la escritura en cache falla, el tick **MUST NOT** fallar; simplemente loguea `warning` y continua.

#### Scenario: Endpoint sirve la cache caliente
- **WHEN** el admin llama `GET /ia/api-transcriptor/regulator-cause` antes del siguiente tick
- **THEN** la respuesta refleja la última decision cacheada aunque el tick no haya vuelto a correr
- **AND** el campo `fired_at` es el timestamp exacto del tick que produjo esa decision

#### Scenario: Tick frio sin decision previa
- **WHEN** el sistema arranca y nunca ha corrido un tick
- **THEN** el endpoint devuelve `fired_at=null`, `signals_evaluated=[]`, `decision=none`, `reason=none`
- **AND** el frontend muestra "Sin datos del regulador" en lugar de error
