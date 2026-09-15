# Despacho del API Transcriptor consciente del consumo remoto

## Why

El tick automático (`transcription:tick`) envía ráfagas de hasta 145 archivos
cada 2 minutos usando como única señal de freno la profundidad de la cola
Redis local (`target_redis_queue=140`). Esa señal no refleja lo que la API
remota (192.168.0.138:9000) puede absorber. La API upstream tiene **3
workers GPU** (`workers=3` en `/api/info`) y un **ramdisk al 91%** que ya
está rechazando envíos con `503 ramdisk_full`. El resultado es observable
en producción:

- 744 jobs en estado `queued` con **5 horas promedio de espera** sin progresar
- 1.160 jobs en `error` en las últimas horas
- Pico histórico de "queued zombies" en cada intento masivo del operador
- 1.690 archivos `pending` esperando encolar

La propuesta `transcriptor-api-surface-completeness` (ya mergeada) trajo el
circuit breaker para los rechazos `429`/`503` y el backoff con `Retry-After`,
pero el regulador sigue siendo local: **nunca mira la API upstream antes de
encolar**.

`transcriptor-api-surface-completeness` también dejó el camino abierto para
activar `regulator_mode=hybrid`, pero el cliente `getRemoteStats()` consulta
`/api/stats` que **devuelve 404** en la API real. La señal de GPU remota
está rota y el modo `hybrid` corre sin ella.

Además, la API upstream expone **`/api/metrics/overview`** (recién publicado,
2026-09-14, público y sin auth) con telemetría rica y de estructura plana
(diseñada específicamente para integración con Grafana/Datadog/Prometheus):

- `node.workers` (capacity real del cluster)
- `gpu.{util_pct, vram_used_pct, temp_c, power_w, model}`
- `ramdisk.{pct, free_gb, used_gb, total_gb, ok}`
- `ram.{pct, used_gb, total_gb}` y `disk.{pct, used_gb, total_gb}`
- `cpu.{pct, load_1m, load_5m, cores}`
- `queue.{total_jobs, by_state_corrected}` (conteos por `state/corrected`)
- `circuit_breakers.{healthy, open, half_open}`

Ese endpoint es **mejor candidato** que `/api/info` para esta propuesta porque:
- Estructura plana pensada para orquestadores (sin `gpu.current.X` anidado)
- Incluye `queue.by_state_corrected` → podemos derivar `processing` directo
  sin contar locales
- Es público (sin fricción de Bearer token)
- Campo `vram_used_pct` con nombre más explícito que `gpu.current.vram_pct`

Esa información ya está disponible pero TCloud no la consume.

## What Changes

### 1. Conectar el regulador al estado real de la API upstream

Reescribir `TranscriptorApiClient::getRemoteInfo()` para consultar
**`/api/metrics/overview`** (recién publicado, plano y público) en vez de
`/api/info`. El método debe:

- Parsear `node.workers` → `capacity` (cupo total del cluster GPU).
- Derivar `processing` desde `queue.by_state_corrected["processing/0"]`
  (conteo que la propia API mantiene, sin tener que consultar la BD local).
- Reportar `usage_pct` y `ramdisk.pct` como señales adicionales.

Mantener **fail-open**: si `/api/metrics/overview` no responde, devuelve
`null` y el regulador no dispara freno por GPU (igual que hoy).

`getRemoteStats()` queda como wrapper que también consume
`/api/metrics/overview` y devuelve el shape backward-compatible
`{processing, capacity, usage_pct}`.

### 2. Batch adaptativo basado en capacidad remota

El campo `max_batch` (default 200) deja de ser un tope estático. Pasa a
calcularse como:

```
max_batch = max(min_batch, ceil(workers * batch_per_worker_ratio))
```

Donde `batch_per_worker_ratio` (nuevo setting, default 1.5) define cuántos
jobs en vuelo por worker. Con `workers=3` y `ratio=1.5` → `max_batch=5`
(sin saturar), en lugar de enviar 200 a ciegas.

### 3. Cadencia de tick conservadora

Cambiar el default de `tick_interval_minutes` de 2 a **3 minutos**. El
operador lo puede bajar a 1 si la API está estable, o subirlo a 5/10 si la
carga es baja. Es ajustable en caliente desde la UI de settings.

### 4. Ramp-up progresivo en lugar de ráfagas

Dentro de un mismo tick, en lugar de encolar `batch` jobs a la vez:

- Calcular `effective_batch` aplicando las 4 señales (redis, gpu, shm, inflight)
- Si `effective_batch > 0`, dividirlo en chunks de tamaño `stagger_chunk_size`
  (nuevo setting, default 5) y separar cada chunk por `dispatch_stagger_ms`
  (existente, default 0 → subir default a 250ms)
- Resultado: con `batch=30` y `chunk=5` se reparten 6 envíos cada 250ms en
  lugar de 30 simultáneos

### 5. Esperar menos para detectar jobs colgados

Hoy un job pasa a `processing` y se queda ahí hasta que `poll_max_age_hours`
(48h) lo cierra como `dead`. Agregar una señal temprana en el regulador:
si `wait_s` devuelto por la API en `/v1/jobs/{jid}` supera un umbral
(`remote_wait_warn_seconds`, default 60s), el regulador trata al job como
"candidato a unstick" y reduce el batch del siguiente tick en `stuck_penalty`
(20% por job stuck, máximo -80%).

### 6. Observabilidad: panel "Consumo en vivo"

Nuevo endpoint `GET /ia/api-transcriptor/live-consumption` que combina:

- `/api/info` (capacity, vram, ramdisk, cpu)
- Conteos locales (`pending`, `queued`, `processing`, `error`, `dead`)
- Métricas derivadas: `jobs_in_flight_per_worker`, `ramdisk_pressure_pct`,
  `vram_pressure_pct`, `last_dispatch_reason`
- Serie temporal (Redis) de los últimos 60 min con conteo por estado

Render en `/ia/api-transcriptor` como una nueva pestaña "Consumo" con:

- Cards: `Workers`, `Procesados/min`, `En vuelo`, `Ramdisk %`, `VRAM %`
- Gráfico sparkline de los últimos 60 min (5 estados)
- Tabla: top 5 jobs más antiguos en `queued` con su `wait_s`

### 7. Backoff informativo para el operador

Cuando el regulador decide frenar (`reason ∈ {remote_gpu_saturated,
ramdisk_pressure_high, wait_threshold_exceeded}`), exponer en la UI:

- Cuál fue la señal ganadora
- Valor que tenía (ej. `ramdisk_pct=91.08 > 90.0`)
- Sugerencia operativa (ej. "Revisar espacio en /mnt/ramdisk del API upstream")

## Capabilities

### New Capabilities

- `transcriptor-consumption-aware-dispatch`: el regulador consulta el estado
  real de la API upstream antes de encolar, adapta el batch a la capacidad
  remota y aplica ramp-up progresivo para evitar picos.

### Modified Capabilities

- `transcriptor-regulator-signals` (introducido en 2026-09-07): la señal
  `remote_gpu_saturated` pasa de consultar `/api/stats` (404) a
  `/api/info` (que existe y tiene los datos). Se añade la señal
  `remote_ramdisk_pressure` con umbral configurable.
- `transcription-orchestrator-runtime`: el cálculo de `max_batch` deja de
  ser un valor fijo en config y pasa a derivarse de `workers * ratio`. El
  default de `tick_interval_minutes` cambia de 2 a 3 minutos.

## Impact

### Código afectado

- `app/app/Services/Ia/TranscriptorApiClient.php`: reescribir `getRemoteStats()`
  para usar `/api/info`; agregar `getRemoteInfo()` que devuelva el payload
  crudo para el panel de observabilidad.
- `app/app/Services/Ia/TranscriptorSettings.php`: agregar claves
  `batch_per_worker_ratio` (float, default 1.5), `stagger_chunk_size`
  (int, default 5), `remote_wait_warn_seconds` (int, default 60),
  `stuck_penalty_pct` (int, default 20), `remote_ramdisk_pressure_pct`
  (int, default 85).
- `app/app/Console/Commands/TranscriptionTickCommand.php`: nuevo método
  `computeEffectiveBatch()` que aplica las 4 señales + adaptativo;
  modificar `evaluateRegulator()` para incluir `remote_ramdisk_pressure`;
  nuevo método `applyStagger()` que reparte el batch en chunks.
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`: nuevo
  endpoint `liveConsumption()` que agrega `/api/info` + DB local.
- `app/resources/views/ia/api-transcriptor/index.blade.php`: nueva pestaña
  "Consumo" con cards + sparkline + tabla de jobs más antiguos.
- `app/app/Services/Dashboard/DashboardDataProvider.php`: el summary
  tibio del dashboard agrega `live_consumption` en el bloque.
- `app/config/transcriptor.php`: agregar claves con defaults razonables,
  cambiar default `tick_interval_minutes` de 2 a 3.

### Migración / settings

- No hay migración de BD.
- En `system_settings`, sembrar `batch_per_worker_ratio=1.5`,
  `stagger_chunk_size=5`, `remote_wait_warn_seconds=60`,
  `stuck_penalty_pct=20`, `remote_ramdisk_pressure_pct=85`.
- Cambiar default `tick_interval_minutes` de 2 a 3. Los overrides del
  operador se preservan.

### Observabilidad nueva

- Log estructurado cada 5 min con `transcriptor.consumption.snapshot` que
  contiene capacity, processing, ramdisk_pct, vram_pct, batch_decision.
- Métrica en Redis `transcriptor:consumption:series` con anillo de 60 min
  (un punto cada minuto).
- Nuevo log channel `transcriptor-consumption` (opcional, desactivado por
  default para no inflar laravel.log).

### Tests / harnesses nuevos

- `tests/harness_consumption_aware_dispatch.php`: mockea `/api/info` con
  `workers=2, ramdisk=92` y verifica que el regulador devuelve
  `reason=remote_ramdisk_pressure` con `batch=0`.
- `tests/harness_consumption_aware_dispatch_rampup.php`: con
  `workers=3, ramdisk=50`, verifica que el regulador devuelve `batch` y
  que los chunks se reparten en `stagger_ms`.
- `tests/harness_remote_info_parser.php`: valida el parser de `/api/info`
  contra respuestas reales (capturadas hoy 2026-09-14).

### Affected specs

- `openspec/specs/transcriptor-regulator-signals/spec.md` (delta):
  añadir `remote_ramdisk_pressure` como nueva señal.
- `openspec/specs/transcription-orchestrator-runtime/spec.md` (delta):
  `max_batch` deja de ser estático.

## Non-goals

- **No** se escala el cluster GPU upstream. La propuesta asume `workers`
  como input del regulador, no como variable a modificar.
- **No** se cambia el algoritmo ASR ni la calidad de la transcripción.
- **No** se introduce webhook (cubierto por Fase D de
  `transcriptor-api-surface-completeness`).
- **No** se reescribe `TranscriptionSubmitService` ni el polling — el
  cambio es de orquestación y observabilidad.
- **No** se hace retry-batch desde esta propuesta (ya cubierto por
  `transcriptor-api-surface-completeness` Fase C).

## Sizing estimado

| Tarea | Esfuerzo | Riesgo | Reversible |
|---|---|---|---|
| Fix `getRemoteStats()` → `/api/info` | 2 h | bajo | sí |
| Batch adaptativo (`workers * ratio`) | 1 h | bajo | sí |
| Tick a 3 min + ramp-up con chunks | 2 h | bajo | sí |
| Señal `remote_ramdisk_pressure` | 1 h | bajo | sí |
| Wait-time awareness + stuck penalty | 2 h | medio | sí |
| Endpoint + UI panel "Consumo" | 4 h | bajo | sí |
| Serie temporal Redis (60 min ring) | 2 h | bajo | sí |
| Harnesses de regresión | 2 h | bajo | sí |
| **Total** | **16 h** | bajo-medio | sí |

## Rollback

- Cambiar `regulator_mode` de vuelta a `local_only` en `system_settings`
  (los overrides del operador se preservan).
- Restaurar el default de `tick_interval_minutes` a 2 (cambio de una
  línea en `config/transcriptor.php` + `php artisan config:cache`).
- `Cache::forget('transcriptor:consumption:series:*')` para limpiar el
  anillo de telemetría.
- `git revert` del merge devuelve al estado anterior sin pérdida de
  datos (no hay migración de BD).

## Operador: knobs principales tras el deploy

| Setting | Default | Efecto |
|---|---|---|
| `transcriptor.regulator_mode` | `hybrid` | Habilita todas las señales (redis + gpu + ramdisk + shm + inflight) |
| `transcriptor.tick_interval_minutes` | `3` | Frecuencia del scan+dispatch |
| `transcriptor.batch_per_worker_ratio` | `1.5` | Cuántos jobs en vuelo por worker GPU |
| `transcriptor.stagger_chunk_size` | `5` | Tamaño del chunk para ramp-up |
| `transcriptor.dispatch_stagger_ms` | `250` | Pausa entre chunks |
| `transcriptor.remote_ramdisk_pressure_pct` | `85` | Umbral para señal `ramdisk_pressure_high` |
| `transcriptor.remote_wait_warn_seconds` | `60` | Umbral para considerar un job como "stuck" |

## Riesgos identificados

- **[Riesgo]** `/api/info` cambia de schema → [Mitigación] el parser valida
  presencia de `workers` y cae a fail-open si falta; log warning una vez
  por proceso.
- **[Riesgo]** El operador tiene `max_batch=200` y el nuevo cálculo
  adaptativo lo baja a 5 → [Mitigación] el setting se respeta como techo
  superior (`max_batch_adaptive = min(max_batch_static, max(workers*ratio, min_batch))`).
- **[Riesgo]** Serie temporal en Redis crece → [Mitigación] anillo de 60
  puntos × 5 bytes = ~300 bytes, despreciable.
