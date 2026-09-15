# Design — api-transcriptor-consumption-aware-dispatch

## Diagrama de flujo del regulador (modo `hybrid`)

```
                        ┌─────────────────────┐
                        │ TranscriptionTick   │
                        │   ::handle()        │
                        └──────────┬──────────┘
                                   │
                                   ▼
                        ┌─────────────────────┐
                        │ Phase 1: discovery  │
                        │  scan-and-submit    │
                        │  --days=0           │
                        │  --no-dispatch      │
                        └──────────┬──────────┘
                                   │
                                   ▼
   ┌───────────────────────────────────────────────────────────┐
   │ Phase 2: evaluateRegulator()                              │
   │                                                           │
   │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐ │
   │  │ /api/info    │  │ Redis LLEN   │  │ UpstreamCircuit  │ │
   │  │              │  │ queues:*     │  │ Breaker          │ │
   │  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘ │
   │         │                 │                    │           │
   │         ▼                 ▼                    ▼           │
   │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐ │
   │  │ workers=3    │  │ 0..N jobs    │  │ open?            │ │
   │  │ ramdisk=91%  │  │              │  │ threshold=3      │ │
   │  │ vram=58%     │  │              │  │ open_secs=60     │ │
   │  │ gpu.util=0%  │  │              │  │                  │ │
   │  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘ │
   │         │                 │                    │           │
   │         ▼                 ▼                    ▼           │
   │  ┌─────────────────────────────────────────────────────┐ │
   │  │ signalScorer (priority order)                       │ │
   │  │   1. circuit_open            -> skip, reason=...   │ │
   │  │   2. ramdisk >= 85%          -> skip, reason=...   │ │
   │  │   3. remote_gpu.util >= 80%  -> skip, reason=...   │ │
   │  │   4. redis_queue >= target   -> skip, reason=...   │ │
   │  │   5. shm_free < 200MB        -> skip, reason=...   │ │
   │  │   6. inflight >= max         -> skip, reason=...   │ │
   │  └─────────────────────────┬───────────────────────────┘ │
   │                            │                             │
   │                            ▼                             │
   │               ┌─────────────────────────┐               │
   │               │ effective_batch =       │               │
   │               │   min(max_static,       │               │
   │               │       ceil(workers*1.5))│               │
   │               │   - stuck_penalty       │               │
   │               └─────────────┬───────────┘               │
   │                             │                            │
   │                             ▼                            │
   │               ┌─────────────────────────┐               │
   │               │ applyStagger()          │               │
   │               │  split into chunks of 5 │               │
   │               │  sleep 250ms between    │               │
   │               └─────────────┬───────────┘               │
   └─────────────────────────────┼───────────────────────────┘
                                 ▼
                  ┌─────────────────────────┐
                  │ ConvertAndTranscribeJob │
                  │  ::dispatch()           │
                  └─────────────────────────┘
```

## `/api/metrics/overview` schema esperado (endpoint dedicado)

La API upstream publicó este endpoint (sin auth, plano, ideal para
orquestadores) el 2026-09-14. Captura en vivo (18:32):

```json
{
  "node": {
    "id": "transcriptor-138",
    "hostname": "23c807ce42f6",
    "uptime_seconds": 7,
    "workers": 3
  },
  "ram": {
    "total_gb": 67.33,
    "used_gb": 39.5,
    "pct": 58.7,
    "swap_total_gb": 2.15,
    "swap_used_gb": 1.29
  },
  "ramdisk": {
    "path": "/mnt/ramdisk",
    "total_gb": 16.0,
    "used_gb": 8.71,
    "free_gb": 7.29,
    "pct": 54.41,
    "ok": true
  },
  "disk": {
    "total_gb": 490.58,
    "pct": 72.5
  },
  "cpu": {
    "pct": 23.7,
    "load_1m": 4.02,
    "load_5m": 4.42,
    "cores": 16
  },
  "gpu": {
    "model": "AMD Radeon RX 7900 XT",
    "vram_total_gb": 21.46,
    "vram_used_pct": 42,
    "util_pct": 100,
    "temp_c": 71.0,
    "power_w": 190.0
  },
  "queue": {
    "total_jobs": 81616,
    "by_state_corrected": {
      "done/-1": 53344,
      "processing/0": 6,
      "done/1": 27663,
      "done/0": 384,
      "queued/0": 219
    }
  },
  "circuit_breakers": {
    "healthy": 1,
    "open": 0,
    "half_open": 0
  },
  "collected_at": 1789428574.50
}
```

El campo **`queue.by_state_corrected["processing/0"]`** da el número de
jobs que están siendo procesados **por la API upstream**, sin tener que
contar en BD local. Esto resuelve un problema del diseño anterior que
derivaba `processing` desde `state='processing'` local — ese conteo puede
ser inexacto si el poll está atrasado.

## Mapeo de campos para el regulador

| Campo upstream | Señal interna | Default umbral |
|---|---|---|
| `node.workers` | `capacity` (entero) | — |
| `queue.by_state_corrected["processing/0"]` | `processing` (entero) | — |
| `circuit_breakers.open > 0` | `circuit_open` (bool) | — |
| `ramdisk.pct` | `remote_ramdisk_pressure_pct` (int) | `>= 85` frena |
| `gpu.util_pct` | `remote_gpu_saturated` (bool) | `>= 80` frena |
| `gpu.vram_used_pct` | `remote_vram_pressure_pct` (int, observabilidad) | no frena |
| `cpu.pct`, `cpu.load_1m` | `remote_cpu_pressure` (observabilidad) | no frena |

## Nuevo método: `TranscriptorApiClient::getRemoteInfo()`

Endpoint dedicado `/api/metrics/overview` (público, plano, sin auth).

```php
public function getRemoteInfo(): ?array
{
    $response = Http::timeout(...)
        ->get($this->baseUrl() . '/api/metrics/overview');

    if (!$response->successful()) return null;

    $data = $response->json();
    if (!is_array($data) || !isset($data['node']['workers'])) return null;

    $gpu = is_array($data['gpu'] ?? null) ? $data['gpu'] : [];
    $ramdisk = is_array($data['ramdisk'] ?? null) ? $data['ramdisk'] : [];
    $queue = is_array($data['queue']['by_state_corrected'] ?? null)
        ? $data['queue']['by_state_corrected'] : [];
    $circuit = is_array($data['circuit_breakers'] ?? null)
        ? $data['circuit_breakers'] : [];

    $processing = (int) ($queue['processing/0'] ?? 0);
    $workers = (int) $data['node']['workers'];

    return [
        'workers'         => $workers,
        'processing'      => $processing,
        'capacity'        => $workers,
        'usage_pct'       => $workers > 0
            ? (int) round(($processing / $workers) * 100)
            : 0,
        'cluster_state'   => 'UP',
        'circuit_open'    => ((int) ($circuit['open'] ?? 0)) > 0,
        'gpu_util_pct'    => max(0, min(100, (int) ($gpu['util_pct'] ?? 0))),
        'gpu_vram_pct'    => max(0, min(100, (int) ($gpu['vram_used_pct'] ?? 0))),
        'gpu_model'       => (string) ($gpu['model'] ?? ''),
        'ramdisk_pct'     => max(0, min(100, (float) ($ramdisk['pct'] ?? 0))),
        'ramdisk_free_gb' => max(0, (float) ($ramdisk['free_gb'] ?? 0)),
        'cpu_pct'         => max(0, min(100, (float) ($data['cpu']['pct'] ?? 0))),
        'cpu_load_1m'     => max(0, (float) ($data['cpu']['load_1m'] ?? 0)),
        'queue_total'     => (int) ($data['queue']['total_jobs'] ?? 0),
        'source'          => '/api/metrics/overview',
        'fetched_at'      => now()->toIso8601String(),
    ];
}

public function getRemoteStats(): ?array
{
    $info = $this->getRemoteInfo();
    if ($info === null) return null;

    return [
        'processing' => $info['processing'],
        'capacity'   => $info['capacity'],
        'usage_pct'  => $info['usage_pct'],
        'source'     => $info['source'],
    ];
}
```

## Por qué `/api/metrics/overview` y no `/api/info`

| Aspecto | `/api/info` | `/api/metrics/overview` |
|---|---|---|
| Auth requerida | sí (Bearer) | no (público) |
| Estructura | anidada (`gpu.current.X`) | plana (`gpu.X`) |
| Campo processing | NO directo | `queue.by_state_corrected["processing/0"]` |
| Diseñado para orquestadores | no | sí |
| Compatibilidad hacia atrás | — | sí (mantenemos `/api/info` como fallback opcional) |

## Cálculo del batch efectivo

```php
private function computeEffectiveBatch(TranscriptorSettings $settings, array $decision): int
{
    if ($decision['decision'] === 'skipped') return 0;

    $current = (int) Redis::llen('queues:transcription');
    $target  = $settings->int('target_redis_queue');
    $runway  = $settings->int('runway');
    $min     = $settings->int('min_batch');
    $maxStatic = $settings->int('max_batch');
    $ratio   = (float) $settings->str('batch_per_worker_ratio') ?: 1.5;

    // Capacidad remota si getRemoteStats() respondio.
    $remote = $decision['remote_stats'] ?? null;
    $workersCapacity = $remote['capacity'] ?? 0;

    $remoteAdaptive = $workersCapacity > 0
        ? (int) ceil($workersCapacity * $ratio)
        : PHP_INT_MAX;

    // Deficit: lo que falta para llegar al target + runway.
    $deficit = max(0, ($target + $runway) - $current);

    // Stuck penalty: si hay jobs viejos en queued, reducir el batch.
    $stuckCount = Transcription::where('state', 'queued')
        ->where('started_at', '<', now()->subSeconds($settings->int('remote_wait_warn_seconds')))
        ->count();
    $stuckPenaltyPct = min(80, $stuckCount * $settings->int('stuck_penalty_pct'));
    $stuckMultiplier = (100 - $stuckPenaltyPct) / 100.0;

    $effective = (int) floor(min($deficit, $maxStatic, $remoteAdaptive) * $stuckMultiplier);
    $effective = max(0, max($min, $effective));

    return $effective;
}
```

## Ramp-up con chunks

```php
private function applyStagger(int $batch, TranscriptorSettings $settings): array
{
    if ($batch === 0) return [];

    $chunkSize = max(1, $settings->int('stagger_chunk_size'));
    $staggerMs = max(0, $settings->int('dispatch_stagger_ms'));

    $chunks = array_chunk(range(0, $batch - 1), $chunkSize);

    if ($staggerMs === 0) return $chunks;

    foreach ($chunks as $i => $chunk) {
        if ($i > 0) usleep($staggerMs * 1000);
        yield $chunk;
    }
}
```

## Endpoint `liveConsumption()`

```php
public function liveConsumption(Request $request): JsonResponse
{
    $apiClient = app(TranscriptorApiClient::class);
    $info = $apiClient->getRemoteInfo();
    $settings = app(TranscriptorSettings::class);

    $local = Transcription::query()
        ->selectRaw("state, COUNT(*) AS n")
        ->where('created_at', '>=', Carbon::today())
        ->groupBy('state')
        ->pluck('n', 'state');

    $series = $this->loadConsumptionSeries(); // Redis ring buffer 60 min

    $oldestQueued = Transcription::where('state', 'queued')
        ->orderBy('updated_at')
        ->limit(5)
        ->get(['id', 'job_id', 'original_name', 'updated_at']);

    return response()->json([
        'remote' => $info ?? ['unreachable' => true],
        'local'  => [
            'pending'    => (int) ($local['pending'] ?? 0),
            'queued'     => (int) ($local['queued'] ?? 0),
            'processing' => (int) ($local['processing'] ?? 0),
            'done_24h'   => (int) ($local['done'] ?? 0),
            'error_24h'  => (int) ($local['error'] ?? 0),
            'dead_24h'   => (int) ($local['dead'] ?? 0),
        ],
        'last_decision'  => Cache::get('transcriptor:tick:last_decision'),
        'series'         => $series,
        'oldest_queued'  => $oldestQueued,
        'fetched_at'     => now()->toIso8601String(),
    ]);
}
```

## UI: nueva pestaña "Consumo" en api-transcriptor/index.blade.php

Tres bloques:

1. **Cards** (top):
   - Workers (capacity) — verde si >0
   - En vuelo / worker — barra con %
   - VRAM % — número grande + sparkline 60min
   - Ramdisk % — número grande + sparkline (rojo si >85)
   - Jobs/min (últimos 5 min)

2. **Decisión del regulador** (read-only, last decision):
   - Si `decision=dispatched`: batch_computed, regulator_mode, "OK"
   - Si `decision=skipped`: reason en rojo + valor + sugerencia

3. **Top 5 jobs más antiguos en `queued`**:
   - Tabla con id, job_id, nombre, hace cuánto se quedó en queued
   - Botón "Re-encolar upstream" por fila (usa `unstick`/`retry`)

## Cambios en `TranscriptorSettings` (schema)

```php
'batch_per_worker_ratio' => [
    'type' => 'str', 'group' => 'ritmo', 'default' => '1.5',
    'env_key' => 'TRANSCRIPTOR_BATCH_PER_WORKER_RATIO',
    'label' => 'Ratio de batch por worker remoto',
    'help' => 'Cuantos jobs en vuelo simultaneos por worker GPU remoto. Con 3 workers y ratio 1.5 el batch maximo es ceil(3*1.5)=5.',
],
'stagger_chunk_size' => [
    'type' => 'int', 'group' => 'ritmo', 'default' => 5, 'min' => 1, 'max' => 50,
    'env_key' => 'TRANSCRIPTOR_STAGGER_CHUNK_SIZE',
    'label' => 'Tamano del chunk de ramp-up',
    'help' => 'El batch se divide en chunks de este tamano y se reparten con stagger_ms entre cada uno.',
],
'remote_ramdisk_pressure_pct' => [
    'type' => 'int', 'group' => 'confiabilidad', 'default' => 85, 'min' => 50, 'max' => 99,
    'env_key' => 'TRANSCRIPTOR_REMOTE_RAMDISK_PRESSURE_PCT',
    'label' => 'Presion ramdisk remoto (%)',
    'help' => 'Si /api/info reporta ramdisk_pct >= este umbral, el regulador frena con reason=remote_ramdisk_pressure.',
],
'remote_wait_warn_seconds' => [
    'type' => 'int', 'group' => 'confiabilidad', 'default' => 60, 'min' => 5, 'max' => 3600,
    'env_key' => 'TRANSCRIPTOR_REMOTE_WAIT_WARN_SECONDS',
    'label' => 'Edad para considerar stuck (s)',
    'help' => 'Un job en queued mas viejo que esto se cuenta como stuck y reduce el batch del siguiente tick.',
],
'stuck_penalty_pct' => [
    'type' => 'int', 'group' => 'confiabilidad', 'default' => 20, 'min' => 5, 'max' => 80,
    'env_key' => 'TRANSCRIPTOR_STUCK_PENALTY_PCT',
    'help' => 'Porcentaje de reduccion del batch por cada job stuck detectado. Maximo acumulado 80%.',
],
```

## Cambios en `tick_interval_minutes`

```diff
- 'tick_interval_minutes' => (int) env('TRANSCRIPTOR_TICK_INTERVAL_MINUTES', 2),
+ 'tick_interval_minutes' => (int) env('TRANSCRIPTOR_TICK_INTERVAL_MINUTES', 3),
```

Si el operador tiene `TRANSCRIPTOR_TICK_INTERVAL_MINUTES=2` en `.env`, ese
override sigue ganando. Solo cambia el default.

## Riesgos y mitigaciones (resumen)

| Riesgo | Mitigación |
|---|---|
| `/api/info` cambia schema | parser valida `workers`; fail-open con warning |
| Operador con `max_batch=200` se sorprende por batch=5 | el setting es techo superior, log informativo |
| Serie temporal crece | anillo de 60 puntos (~300 bytes) |
| Stagger_ms alto retrasa la respuesta HTTP del tick | el stagger se hace ANTES del return, el tick tarda N*stagger pero libera al socket entre chunks |

## Plan de despliegue

1. PR con `proposal.md` + `design.md` + `tasks.md` + 1 spec delta
2. Review del operador
3. Merge
4. `php artisan config:cache` (default tick_interval_minutes=3)
5. Los nuevos settings se siembran en system_settings desde migración CLI
6. Verificación: `tail -f transcription-tick.log` durante 30 min
7. Validar UI: panel "Consumo" muestra datos reales
8. Rollback disponible con `git revert`
