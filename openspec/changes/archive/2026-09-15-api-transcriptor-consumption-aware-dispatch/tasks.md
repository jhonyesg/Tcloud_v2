# Tasks — api-transcriptor-consumption-aware-dispatch

## Resumen

| Fase | Tracks | Esfuerzo | Predecesoras |
|---|---|---|---|
| A — Telemetria real | TA1..TA4 | 3 h | — |
| B — Regulador adaptativo | TB1..TB5 | 5 h | A |
| C — Ramp-up progresivo | TC1..TC3 | 2 h | B |
| D — Observabilidad | TD1..TD5 | 4 h | B |
| E — Harnesses | TE1..TE3 | 2 h | A, B, C |

Total: **16 h**, secuenciadas. Cada fase rollbackeable independientemente.

## Fase A — Telemetría real (consultar /api/metrics/overview)

- [x] TA1 — Client: nuevo método `getRemoteInfo(): ?array` que consulta
  `/api/metrics/overview` (endpoint público, plano, sin auth) y devuelve
  payload normalizado con `processing` derivado de
  `queue.by_state_corrected["processing/0"]`.
- [x] TA2 — Client: reescribir `getRemoteStats()` para usar `getRemoteInfo()`
  como backing store. Mantener shape `{processing, capacity, usage_pct, source}`.
- [x] TA3 — Settings: agregar `regulator_remote_info_path` (default
  `/api/metrics/overview`); `regulator_remote_timeout_ms` ya existe.
- [x] TA4 — Harness: `harness_remote_info_parser.php` valida el parser
  contra respuestas reales del overview (capturadas el 2026-09-14 18:32).

### TA1 — `getRemoteInfo()`

- Output: método público que devuelve `array|null` con keys:
  `workers, cluster_state, circuit_open, gpu_util_pct, gpu_vram_pct,
  ramdisk_pct, cpu_pct, version, fetched_at`.
- Done: el método se llama desde un comando de artisan sin errores y los
  valores son correctos contra una respuesta real de `/api/info`.

### TA2 — Reescritura de `getRemoteStats()`

- Output: `getRemoteStats()` queda como wrapper sobre `getRemoteInfo()`.
- Criterio: `grep -A3 'getRemoteStats' TranscriptorApiClient.php` muestra
  una sola implementación que llama a `getRemoteInfo()` internamente.

### TA3 — Settings

- Output: clave nueva `regulator_remote_info_path` (string, default
  `/api/info`) en `TranscriptorSettings::SCHEMA` y en
  `config/transcriptor.php`.
- Done: `app(App\Services\Ia\TranscriptorSettings::class)->str('regulator_remote_info_path')`
  devuelve `/api/info`.

### TA4 — Harness `harness_remote_info_parser.php`

- Output: harness que mockea respuesta `/api/info` con `workers=2,
  ramdisk=92, vram=80, util=95` y verifica:
  - `getRemoteInfo()` devuelve los 9 campos esperados
  - `getRemoteStats()` calcula `usage_pct` correctamente
  - con `/api/info` retornando 404, devuelve `null` (fail-open)
- Criterio DONE: `exit 0`.

## Fase B — Regulador adaptativo

- [x] TB1 — `evaluateRegulator()` incluye nueva señal `remote_ramdisk_pressure`
  antes de `remote_gpu_saturated` (mayor prioridad porque es más rápido
  de aparecer).
- [x] TB2 — Settings: agregar `batch_per_worker_ratio`,
  `remote_ramdisk_pressure_pct`, `remote_wait_warn_seconds`, `stuck_penalty_pct`.
- [x] TB3 — `computeEffectiveBatch()`: nuevo método que toma la decisión
  cruda y le aplica el cálculo adaptativo + stuck penalty.
- [x] TB4 — Wire en `handle()`: el tick usa `computeEffectiveBatch()` en
  vez de la fórmula estática.
- [x] TB5 — Cambio de default `tick_interval_minutes` de 2 a 3 minutos
  en `config/transcriptor.php` y `TranscriptorSettings::SCHEMA`.

### TB1 — Señal `remote_ramdisk_pressure`

- Output: nueva entrada en el array de señales del regulador. Lee
  `info.ramdisk_pct >= settings->int('remote_ramdisk_pressure_pct')`.
- Criterio: si `ramdisk_pct=91.08` y `remote_ramdisk_pressure_pct=85`,
  `decision=skipped, reason=remote_ramdisk_pressure, value=91.08`.

### TB3 — `computeEffectiveBatch()`

- Output: nuevo método privado en `TranscriptionTickCommand`. Fórmula:
  `min(deficit, max_static, ceil(workers*ratio)) * (1 - stuck_penalty)`.
- Criterio: con `workers=3, ratio=1.5, max_static=200` → `max=5`.
  Con `deficit=145, stuck=2, penalty=20%` → `effective = min(145, 200, 5) * 0.6 = 3`.

### TB4 — Wire en handle()

- Output: el tick llama a `computeEffectiveBatch()` después de
  `evaluateRegulator()` y registra el resultado en
  `transcriptor:tick:last_decision`.
- Criterio: log del tick muestra `batch_computed=N` con el valor real
  del batch adaptativo (no el estático).

### TB5 — Default `tick_interval_minutes`

- Output: cambiar el default de 2 a 3 en `config/transcriptor.php` y en
  `TranscriptorSettings::SCHEMA`.
- Criterio: `schedule:list` muestra `transcription:tick` con `Next Due`
  alineado a 3 min (no 2). Los overrides del operador en `.env` o
  `system_settings` siguen ganando.

## Fase C — Ramp-up progresivo

- [x] TC1 — Settings: agregar `stagger_chunk_size` (default 5).
- [x] TC2 — `applyStagger()`: nuevo método privado que divide el batch
  en chunks y los procesa con `usleep($staggerMs * 1000)` entre cada uno.
- [x] TC3 — Wire en el loop de dispatch: el loop de despacho del tick
  ahora itera sobre `applyStagger($batch)` en vez de encolar todos los
  jobs de golpe.

### TC1 — Setting `stagger_chunk_size`

- Output: nuevo setting con default 5.
- Done: aparece en la UI de settings bajo el grupo `ritmo`.

### TC2 — `applyStagger()`

- Output: generator que devuelve arrays de tamaño `chunk_size`.
- Criterio: con `batch=30, chunk=5, stagger_ms=250`, devuelve 6 chunks
  y entre cada uno duerme 250ms (total ~1.5s para el batch completo).

### TC3 — Wire en loop de despacho

- Output: el loop `for ($i = 0; $i < $batch; $i++) { ConvertAndTranscribeJob::dispatch(...) }`
  se reemplaza por `foreach (applyStagger($batch) as $chunk) { foreach ($chunk as $i) { ... } }`.
- Criterio: el dispatch está visiblemente escalonado (timestamps en el
  log separados por ~stagger_ms).

## Fase D — Observabilidad

- [x] TD1 — Endpoint: `GET /ia/api-transcriptor/live-consumption` con
  payload que combina `/api/metrics/overview` + DB local + last decision + series.
- [x] TD2 — Serie temporal: anillo de 60 puntos en Redis
  (`transcriptor:consumption:series:{min}` con TTL 70min).
- [x] TD3 — Snapshot cron: `transcription:consumption-snapshot` (cada
  5 min) que escribe un punto en la serie con
  `{pending, queued, processing, done_5min, ramdisk_pct, vram_pct}`.
- [x] TD4 — UI: nueva pestaña "Consumo" con cards + decisión + tabla.
- [x] TD5 — Specs: el archivo specs/transcriptor-regulator-signals.md
  ya cubre los nuevos REQUIREMENTs (remote_ramdisk_pressure, adaptative
  batch).

### TD1 — Endpoint `liveConsumption()`

- Output: método público en `ApiTranscriptorController`. Ruta:
  `GET /ia/api-transcriptor/live-consumption` en `routes/web.php`.
- Criterio: `curl /ia/api-transcriptor/live-consumption` devuelve 200 con
  todas las secciones del payload poblado.

### TD2 — Serie temporal (anillo Redis)

- Output: helper `ConsumptionSeries::record(array $point): void` y
  `ConsumptionSeries::load(int $minutes = 60): array` en
  `app/app/Services/Ia/`.
- Criterio: las keys usan TTL 70min, así el anillo se autorregula.

### TD3 — Snapshot cron

- Output: comando `transcription:consumption-snapshot` registrado en
  `routes/console.php` con `everyFiveMinutes()`.
- Criterio: `schedule:list` lo muestra.

### TD4 — UI "Consumo"

- Output: nueva pestaña en `resources/views/ia/api-transcriptor/index.blade.php`.
  Estructura:
  - 5 cards con valores grandes (Workers, En vuelo, VRAM, Ramdisk, Jobs/min)
  - Bloque "Decisión del regulador" (read-only)
  - Tabla "Top 5 jobs más antiguos en queued" con botón "Re-encolar"
- Criterio: la pestaña es navegable y se auto-refresca cada 30s.

### TD5 — Spec delta

- Output: `openspec/specs/transcriptor-regulator-signals/spec.md` con
  una nueva sección "REQUIREMENT: remote_ramdisk_pressure".
- Criterio: el spec pasa `openspec validate transcriptor-regulator-signals`.

## Fase E — Harnesses de regresión

- [x] TE1 — `harness_consumption_aware_dispatch.php`: mockea `/api/metrics/overview`
  con `workers=3, ramdisk=91, gpu_util=50` y verifica que el regulador
  devuelve `reason=remote_ramdisk_pressure` con `batch=0`.
- [x] TE2 — `harness_consumption_aware_dispatch_rampup.php`: con
  `chunk=5, stagger=50ms` sobre batch=30, verifica que se generan 6
  chunks de 5 IDs con pausa entre cada uno.
- [x] TE3 — `harness_remote_info_parser.php` (TA4): parser de `/api/metrics/overview`
  contra respuestas reales capturadas el 2026-09-14.

### TE1 — Harness freno por ramdisk

- Output: harness que setea `regulator_remote_info_path=/__mock__/api/info-saturated`,
  ejecuta `transcription:tick --dry-run`, captura el output y verifica:
  - `decision=skipped`
  - `reason=remote_ramdisk_pressure`
  - `value>=85`
  - `batch_computed=0`
- Criterio DONE: `exit 0`.

### TE2 — Harness ramp-up

- Output: harness que setea `/__mock__/api/info-healthy`, ejecuta el
  tick y verifica:
  - `decision=dispatched`
  - `batch_computed >= min_batch`
  - `stagger_ms_applied=true` en el log
- Criterio DONE: `exit 0`.

## Cierre global

- [x] CIERRE — `php artisan schedule:list` muestra `transcription:tick` con
  `everyTwoMinutes` (cron fijo, autolimit interno respeta `tick_interval_minutes=3`)
  y `transcription:consumption-snapshot` cada 1 min registrado.
- [x] CIERRE — 3 harnesses pasan con `exit 0` (harness_remote_info_parser,
  harness_consumption_aware_dispatch, harness_consumption_aware_dispatch_rampup)
- [x] CIERRE — UI "Consumo" muestra datos reales (endpoint
  /ia/api-transcriptor/live-consumption responde 200 con todas las
  secciones; nueva pestaña "Consumo" en index.blade.php renderiza
  5 cards + decisión + tabla de jobs más antiguos)
- [x] CIERRE — `grep "regulator_remote_info_path" config/transcriptor.php` → 1 hit
- [x] CIERRE — `grep "remote_ramdisk_pressure" app/app/Console/Commands/TranscriptionTickCommand.php` → >=1 hit (6 hits)
- [x] CIERRE — Post-deploy: 24h monitor
  - jobs/min procesados estable (no picos)
  - ramdisk del API upstream se mantiene bajo 90%
  - jobs en `queued` con `wait_s > remote_wait_warn_seconds` se reducen
