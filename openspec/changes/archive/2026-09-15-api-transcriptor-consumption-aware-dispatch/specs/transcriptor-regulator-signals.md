# Spec delta: transcriptor-regulator-signals

## ADDED Requirements

### REQUIREMENT: remote_ramdisk_pressure signal

El regulador SHALL evaluar el porcentaje de uso del ramdisk del API
upstream (`/api/info::host_ramdisk.pct`) en cada tick.

Cuando `ramdisk_pct >= remote_ramdisk_pressure_pct` (default 85%):

- `decision` SHALL ser `skipped`
- `reason` SHALL ser `remote_ramdisk_pressure`
- `value` SHALL ser el `ramdisk_pct` observado
- `batch_computed` SHALL ser `0`

La prioridad de evaluación SHALL ser:

1. `upstream_circuit_open`
2. `remote_ramdisk_pressure` (NUEVO)
3. `remote_gpu_saturated`
4. `redis_queue_depth`
5. `shm_free_bytes`
6. `inflight_full`

#### Scenario: ramdisk al 91% con API al 90% de jobs

- Given: `/api/info` devuelve `host_ramdisk.pct=91.08`
- And: `remote_ramdisk_pressure_pct=85`
- And: `gpu.current.util_pct=95` (>80, también dispararía gpu_saturated)
- When: el tick evalúa el regulador
- Then: `decision=skipped`
- And: `reason=remote_ramdisk_pressure`
- And: `value=91.08`
- And: `batch_computed=0`

#### Scenario: ramdisk normal, GPU saturada

- Given: `/api/info` devuelve `host_ramdisk.pct=42`
- And: `gpu.current.util_pct=92`
- When: el tick evalúa el regulador
- Then: `decision=skipped`
- And: `reason=remote_gpu_saturated`
- And: `batch_computed=0`
- And: NO se reporta `remote_ramdisk_pressure`

### REQUIREMENT: adaptative batch size

El cálculo del batch efectivo SHALL combinar tres límites:

1. **Deficit**: `max(0, target_redis_queue + runway - redis_queue_depth)`
2. **Adaptativo**: `ceil(remote_capacity * batch_per_worker_ratio)` donde
   `remote_capacity` viene de `/api/info::workers`
3. **Estático**: `max_batch` del setting (techo superior)

`effective_batch = max(min_batch, floor(min(deficit, adaptativo, estatico) * stuck_multiplier))`

donde `stuck_multiplier = 1 - min(0.8, stuck_count * stuck_penalty_pct / 100)`.

#### Scenario: cluster con 3 workers, ratio 1.5

- Given: `workers=3, batch_per_worker_ratio=1.5, max_batch=200`
- And: `redis_queue_depth=0, target_redis_queue=140, runway=5`
- And: `stuck_count=0`
- When: el tick calcula el batch
- Then: `effective_batch = max(10, floor(min(145, 5, 200) * 1.0)) = 5`

#### Scenario: 2 jobs stuck detectados

- Given: `stuck_count=2, stuck_penalty_pct=20`
- And: resto de parámetros como arriba
- When: el tick calcula el batch
- Then: `effective_batch = floor(5 * (1 - 0.4)) = 3`
