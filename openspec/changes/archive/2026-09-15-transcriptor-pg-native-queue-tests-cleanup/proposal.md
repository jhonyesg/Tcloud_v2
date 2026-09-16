# transcriptor-pg-native-queue-tests-cleanup

## Why

`vendor/bin/phpunit --filter TranscriptorSettingsTest` corre 24 tests, 4 fallan — los rojos existían ya desde el cutover `transcriptor-pg-native-queue` (que dejó el file `tests/Unit/TranscriptorSettingsTest.php` sin migrar a `target_pg_queue` y sin reflejar el estado actual del operator workflow). El cambio `purge-redis-words-transcriptor` (archivado 2026-09-15) arregló las 11 referencias a la key legacy en ese mismo archivo y dejó los 4 rojos intactos para este change dedicado.

## What Changes

- **`config/transcriptor.php`**: agregar **10** entries que faltan en el config pero están en el schema (todos los settings caen al default del schema en runtime, pero el guardrail test exige la alineación):
  - `submit_with_idempotency_key` (bool, default `true`).
  - `burst_max_upstream_queue` (int, default `180`).
  - `burst_batch_size` (int, default `40`).
  - `burst_parallel_ffmpeg` (int, default `4`).
  - `burst_poll_interval_seconds` (int, default `10`).
  - `max_backoff_seconds` (int, default `300`).
  - `circuit_breaker_threshold` (int, default `3`).
  - `circuit_breaker_open_seconds` (int, default `60`).
  - `submit_with_callback` (bool, default `false`).
  - `webhook_secret` (str, default `''`).
- **`tests/Unit/TranscriptorSettingsTest.php`**: reescribir las 3 tests rojas (`testElFrenoActuaJustoEnElLimite`, `testConMargenAmplioSeAplicaElTechoMaxBatch`, `testRechazaMinBatchMayorQueMaxBatch`) para fijar su baseline con `seedOverrides()` antes de evaluar el método bajo prueba, en vez de depender de los defaults de `config/transcriptor.php`. La cuarta test roja (`testTodaClaveDelEsquemaTieneRespaldoEnConfig`) queda resuelta por la adición de los 10 config entries.
- **Preservado**: la BD del operador NO se toca. Los overrides son intencionales y reflejan producción vigente.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

`transcriptor-jobs-listing` y otros specs adyacentes: ninguno cambia a nivel de comportamiento. Cambio 100% test-fixture + 1 entry de config que ya debería existir.

## Non-goals

- NO modificar overrides del operador en `system_settings`. Sus valores (`min_batch=300`, `max_batch=500`, `runway=10`, `target_redis_queue=800`, etc.) son tuning operativo vigente.
- NO migrar `transcriptor.target_redis_queue = 800` a `transcriptor.target_pg_queue`. Esa parte del cutover sigue pendiente por decisión del operador; queda registrada como caveat.
- NO retocar settings adicionales del schema.
- NO limpiar `transcriptor.dispatch_stagger_ms = 250` (legacy, también tarea pendiente).

## Impact

**Archivos modificados (4):**

- `app/config/transcriptor.php`: +1 entry (`submit_with_idempotency_key`).
- `app/tests/Unit/TranscriptorSettingsTest.php`: 3 tests reescritos para usar baseline fijado.

**APIs / rutas / modelos**: ninguno cambia.

**Migraciones de BD**: ninguna.

**Riesgo**: muy bajo. +1 config key + 3 tests reescritos, sin tocar lógica de producción.

**Rollback**: `git revert <commit>`. Sin estado persistente que limpiar.

## Caveats heredados del cutover (registrados, no resueltos aquí)

Estos quedan como trabajo pendiente del change `transcriptor-pg-native-queue` (sigue activo, 64/77 tareas):

1. **Override legacy `transcriptor.target_redis_queue = 800`**: el setting canónico ahora es `target_pg_queue`. Si el operador quiere migrar el valor, ejecutar `transcriptor:purge-backlog` con `--execute` o un UPSERT manual.
2. **Override legacy `transcriptor.dispatch_stagger_ms = 250`**: setting retirado en el cutover. La fila en `system_settings` queda como ruido pero no afecta runtime (ningún reader).
3. **Override `transcriptor.stagger_chunk_size` y `transcriptor.batch_per_worker_ratio`**: retirados por `purge-redis-words-transcriptor` 2026-09-15. Si están en `system_settings`, son no-ops hasta limpieza manual.
4. **Tests**: con este change aplicado, los 21 verdes previos siguen verdes y los 4 rojos pasan a verde.
