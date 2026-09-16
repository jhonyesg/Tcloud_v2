# transcriptor-pg-native-queue-tests-cleanup — Tasks

## 1. Baseline

- [x] 1.1 Confirmar baseline de rojo:
  ```bash
  cd app && vendor/bin/phpunit --filter TranscriptorSettingsTest 2>&1 | grep -E '^[0-9]+\)'
  ```
  **Confirmado: 4 fallas en este orden:**
  1. `testElFrenoActuaJustoEnElLimite` — `computeDispatchBatch(145)` retorna 300 cuando espera 0 (deficit real 0, pero con overrides en BD `runway=10` lo vuelve 5, elevado al piso `min_batch=300`).
  2. `testConMargenAmplioSeAplicaElTechoMaxBatch` — `computeDispatchBatch(0)` retorna 150 cuando espera 145 (deficit real 140+10=150 con overrides, elevado a min_batch=300).
  3. `testRechazaMinBatchMayorQueMaxBatch` — `set(['min_batch' => 300])` no dispara ValidationException porque el `max_batch` efectivo desde la BD es 500.
  4. `testTodaClaveDelEsquemaTieneRespaldoEnConfig` — `submit_with_idempotency_key` no existe en `config/transcriptor.php` aunque sí está en el schema.

## 2. Config: alinear 10 entries del schema al config

- [x] 2.1 `app/config/transcriptor.php`: agregar 10 entries que el schema declara pero el config no respalda. Defaults tomados de `TranscriptorSettings::SCHEMA`. Orden y agrupamiento semántico:
  ```php
  // § idempotency (existente en schema, alineado)
  'submit_with_idempotency_key' => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_IDEMPOTENCY_KEY', true),

  // § saturación: 503 backoff y circuit breaker
  'max_backoff_seconds'           => (int) env('TRANSCRIPTOR_MAX_BACKOFF_SECONDS', 300),
  'circuit_breaker_threshold'     => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_THRESHOLD', 3),
  'circuit_breaker_open_seconds'  => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_OPEN_SECONDS', 60),

  // § burst dispatcher (TranscriptorBurstDispatchCommand)
  'burst_max_upstream_queue'      => (int) env('TRANSCRIPTOR_BURST_MAX_UPSTREAM_QUEUE', 180),
  'burst_batch_size'              => (int) env('TRANSCRIPTOR_BURST_BATCH_SIZE', 40),
  'burst_parallel_ffmpeg'         => (int) env('TRANSCRIPTOR_BURST_PARALLEL_FFMPEG', 4),
  'burst_poll_interval_seconds'   => (int) env('TRANSCRIPTOR_BURST_POLL_INTERVAL_SECONDS', 10),

  // § webhook (Fase D, experimental)
  'submit_with_callback'          => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_CALLBACK', false),
  'webhook_secret'                => env('TCLOUD_WEBHOOK_SECRET', ''),
  ```
  Nota: el archivo tiene un bloque histórico `// === Claves que vivían solo en el esquema...` que justifica entries similares. Este grupo de 10 nuevas va justo después de `submit_with_idempotency_key` para mantener la coherencia con el bloque histórico.

## 3. Tests: baseline fijado con `seedOverrides`

## 3. Tests: baseline fijado con `seedOverrides`

- [x] 3.1 Reescribir `testElFrenoActuaJustoEnElLimite` (línea 94-103 del archivo):
  - Reemplazar `Cache::forget(self::CACHE_KEY);` por
    `$this->seedOverrides(['target_pg_queue' => 140, 'runway' => 5, 'min_batch' => 10, 'max_batch' => 200]);`.
  - Comentario del test: aclarar que el `seedOverrides` es baseline de prueba, no producción.
  - Verificar que el assert `assertSame(0, computeDispatchBatch(145))` pasa con `deficit = 140 - 145 + 5 = 0`.
  - Verificar que el assert `assertSame(10, computeDispatchBatch(144))` pasa con `deficit = 140 - 144 + 5 = 1` → max(10, min(200, 1)) = 10.
- [x] 3.2 Reescribir `testConMargenAmplioSeAplicaElTechoMaxBatch` (línea 105-114):
  - Primer assert: `seedOverrides(['target_pg_queue' => 140, 'runway' => 5, 'min_batch' => 10, 'max_batch' => 200])` antes del assertEqual `assertSame(145, computeDispatchBatch(0))`.
  - Segundo assert: ya usa `seedOverrides` (línea 112), solo verificar que sigue el patrón y que `assertSame(200, computeDispatchBatch(0))` pasa con `deficit = 1000 - 0 + 5 = 1005` → max(10, min(200, 1005)) = 200.
- [x] 3.3 Reescribir `testRechazaMinBatchMayorQueMaxBatch` (línea 175-186):
  - Reemplazar `Cache::forget(self::CACHE_KEY);` por
    `$this->seedOverrides(['min_batch' => 300, 'max_batch' => 200]);` antes del `$this->settings()->set(...)`.
  - Verificar que `crossFieldErrors` dispara con `'min_batch' => 'El lote minimo no puede superar al maximo.'`.
- [x] 3.4 (Sin tocarse, solo verificar) `testMinBatchCeroPermiteLotesPequenos` (línea 116-122): ya usa `seedOverrides` correctamente, queda como guía de patrón.

## 4. Verificación post-implementación

- [x] 4.1 `cd app && rm -rf .phpunit.cache && rm -f bootstrap/cache/config.php && vendor/bin/phpunit --filter TranscriptorSettingsTest` debe correr **24/24 verde**.
  - **Verificado: OK (24 tests, 176 assertions).**
- [x] 4.2 `cd app && vendor/bin/phpunit --filter 'TranscriptorSettingsTest::testTodaClaveDelEsquemaTieneRespaldoEnConfig'` debe pasar individualmente.
  - **Verificado: pasa.**
- [x] 4.3 `cd app && php -l tests/Unit/TranscriptorSettingsTest.php && php -l config/transcriptor.php` sin errores de sintaxis.
  - **Verificado: ambos OK.**
- [x] 4.4 (Smoke) `cd app && vendor/bin/phpunit --filter TranscriptorSettingsTest --testdox` muestra todos los métodos con sufijo verde.
  - **Verificado por conteo 24/24.**

## 5. Caveats (no resueltos aquí, registrados en proposal.md)

- `transcriptor.target_redis_queue = 800` (legacy) sigue en `system_settings`. Decisión del operador.
- `transcriptor.dispatch_stagger_ms = 250` (legacy) sigue. Setting retirado en cutover; el row es no-op hasta limpieza manual.
- Overrides vigentes del operador: `min_batch=300`, `max_batch=500`, `runway=10`, `regulator_mode=hybrid`, `tick_interval_minutes=1`, `pulse_batch_size=300`, `remote_wait_warn_seconds=300`, `stuck_penalty_pct=2`. Tests futuros deben usar `seedOverrides` para fijar baseline, NO asumir defaults.

**Resultado final:**
- Tests rojos al inicio: 4.
- Tests verdes al final: 24/24.
- Config: 10 nuevas entries (alineación schema↔config).
- Tests: 3 reescritos para usar `seedOverrides` en vez de depender de BD.

## 5. Caveats (no resueltos aquí, registrados en proposal.md)

- `transcriptor.target_redis_queue = 800` (legacy) sigue en `system_settings`. Decisión del operador.
- `transcriptor.dispatch_stagger_ms = 250` (legacy) sigue. Setting retirado en cutover; el row es no-op hasta limpieza manual.
- Overrides vigentes del operador: `min_batch=300`, `max_batch=500`, `runway=10`, `regulator_mode=hybrid`, `tick_interval_minutes=1`, `pulse_batch_size=300`, `remote_wait_warn_seconds=300`, `stuck_penalty_pct=2`. Tests futuros deben usar `seedOverrides` para fijar baseline, NO asumir defaults.
