# Spec Delta — Transcription Orchestrator Runtime

> Delta sobre `openspec/specs/transcription-orchestrator-runtime/spec.md`.
> Motivo: la spec vigente documenta un freno del regulador que el código nunca ejecuta, una superficie de configuración exclusivamente por `env`, y un contrato de pool de workers que la realidad viola desde hace tiempo.

## MODIFIED Requirements

### 1. Unified Tick Phase (modificado)

- The system **SHALL** run `transcription:tick` on a `* * * * *` cadence via Laravel Scheduler, and the command **SHALL** self-throttle against `tick_interval_minutes` (default `2`) using a cache timestamp.
  - *Razón del cambio*: la cadencia fija `*/2 * * * *` no era ajustable sin editar `routes/console.php` y recargar el scheduler. El autolimitado por caché deja la frecuencia regulable en caliente sin tocar código ni cron.
- `withoutOverlapping(150)` **MUST** remain in place.
- The tick **MUST** remain the only scheduled entry point for transcription work.

### 3. Phase 2 — Regulator dispatch (modificado)

- The regulator formula **MUST** evaluate the deficit **before** applying the minimum clamp:
  ```
  target  = settings->int('target_redis_queue')     # default 140
  min     = settings->int('min_batch')              # default 10
  max     = settings->int('max_batch')              # default 200
  runway  = settings->int('runway')                 # default 5
  current = (int) Redis::llen('queues:transcription')

  deficit = target - current + runway
  if (deficit <= 0) -> skip dispatch, return SUCCESS
  batch = max(min, min(max, deficit))
  ```
  - *Razón del cambio*: la fórmula anterior aplicaba `max($min, ...)` **antes** del freno, haciendo que `batch <= 0` fuera inalcanzable con `min_batch=10`. Con la cola en 300 y objetivo 140, el cálculo daba `-155` y se elevaba a `10`, inyectando 10 jobs cada 2 minutos sobre una cola ya saturada. El freno que esta misma spec exigía era código muerto. `min_batch` es ahora un piso que solo aplica cuando existe margen real.
- The values **MUST** be read through `App\Services\Ia\TranscriptorSettings`, not directly from `config()`, so that changes take effect in long-lived `queue:work` processes without a systemd restart.
- When `dispatch_paused` is `true`, the tick **SHALL** complete Phase 1 (discovery) normally and **SHALL** skip Phase 2 entirely, logging the reason. Nothing is lost; only enqueueing stops.
- When `dispatch_stagger_ms` is greater than `0`, the tick **SHALL** stagger each dispatch by `index * dispatch_stagger_ms` via `->delay()`, so a batch enters Redis progressively rather than in a single instant.

### 6. Auto-tuner Phase (modificado)

- The tuner **MUST** compute `workers = clamp(medios_total / worker_ratio, worker_min, worker_max)`, where all three bounds are read from `TranscriptorSettings` (defaults `6`, `3`, `12` — the previous `private const` values).
- The effective `worker_max` **MUST** be capped at the number of installed `tcloud-transcription-batch-*.service` unit files, and that cap **MUST** be exposed to the settings API so the UI cannot request workers that do not exist.
- When `worker_override` is non-zero, the tuner **SHALL** use it verbatim instead of the ratio formula. This is the operator's direct lever during saturation.
- The tuner **MUST** run `reconcileForbiddenPools()` on every `--apply`: enumerate active `tcloud-transcription-worker@*.service` instances, `systemctl disable --now` each one, report them under `stopped_orphans[]` in the JSON summary, and emit a `Log::warning`.
  - *Razón del cambio*: §7 prohibía esas instancias pero nada las hacía cumplir. Verificado el 2026-07-26: 10 instancias `worker@{1..10}` activas junto a 11 `batch-N`, es decir **21 workers reales** frente a los 11 que el tuner creía gestionar, alimentando una API con 2 workers GPU. El contrato pasa de declarativo a autoaplicado.
- The JSON summary line **SHALL** additionally contain `{worker_min, worker_max, worker_ratio, worker_override, stopped_orphans[]}`.
- Idempotence **MUST** hold: a back-to-back run with unchanged inputs emits empty `started`, `stopped` and `stopped_orphans`.

### 9. Configuration Surface (modificado)

- The runtime configuration surface **SHALL** be resolved by `App\Services\Ia\TranscriptorSettings` in this precedence order:
  1. Row in `system_settings` with key `transcriptor.<key>`
  2. `config("transcriptor.<key>")` (env-driven, as today)
  3. Schema default
- Values **MUST** be clamped to the schema's `min`/`max` **on read as well as on write**, so a corrupt row can never produce an out-of-range runtime value.
- The service **MUST** refresh its cached map on a TTL (cache 60 s, in-process memo 30 s) rather than memoizing once. `queue:work` processes live for hours; a constructor-time read would freeze settings until a systemd restart.
- **No** service provider **SHALL** overlay DB values onto the config repository: that would add a DB hit to every boot, break `artisan` with the database down, and hide the value's origin.
- Deleting a `transcriptor.*` row **SHALL** restore the `config/transcriptor.php` value. This three-state distinction (`bd` / `env` / `archivo`) **MUST** be reported by `effective()` and surfaced in the UI.
- New keys, over and above those already listed in the original §9:
  - `dispatch_paused` (bool, default `false`) — emergency brake
  - `tick_interval_minutes` (int, default `2`)
  - `dispatch_stagger_ms` (int, default `0`)
  - `inflight_max` (int, default `0` = disabled)
  - `scan_max_dispatch_per_cycle` (int, default `200`)
  - `stale_resend_limit` (int, default `50`) — was hardcoded in `PollResultsCommand`
  - `poll_limit` (int, default `140`) — was hardcoded at `100`, below the queue target
  - `submit_max_attempts` (int, default `3`), `submit_retry_base_ms` (int, default `500`)
  - `worker_min` / `worker_max` / `worker_ratio` / `worker_override` (int, defaults `3` / `12` / `6` / `0`)
  - `ui_max_parallel_sends` (int, default `3`), `ui_batch_max` (int, default `200`)
  - `callback_host` — **display only**. Mapped to the already-present `TRANSCRIPTOR_CALLBACK_HOST` so the view stops rendering blank. The prohibition on wiring a `TRANSCRIPTOR_WEBHOOK_*` runtime path **remains in force**.
- `retry_after` on the `redis` queue connection **MUST** exceed `ConvertAndTranscribeJob::$timeout`. Default raised from `90` to `900`.
  - *Razón del cambio*: con `retry_after=90` y `$timeout=600`, Redis devolvía a la cola todo job de más de 90 s mientras el worker original seguía en ffmpeg, produciendo dos ffmpeg y dos POST del mismo archivo. La guarda de idempotencia por `job_id` no cubre esa ventana: solo actúa una vez la primera copia ha escrito `job_id`.

## ADDED Requirements

### 10. Concurrency semaphore

- The system **SHALL** provide a job middleware bounding **concurrent ffmpeg + POST execution**, independent of worker count, via `Redis::funnel('transcriptor:inflight')->limit(inflight_max)->releaseAfter(650)`.
- When `inflight_max <= 0` the middleware **MUST** pass through unchanged (feature disabled by default).
- `releaseAfter` **MUST** exceed the job's `$timeout` so a killed worker releases its slot rather than holding it permanently.
- `ConvertAndTranscribeJob` **MUST** use `$tries = 0` plus `retryUntil(now()->addHours(2))`. `release()` increments `attempts()`, so the previous `$tries = 1` would permanently fail the first job the semaphore blocked.
- *Motivo*: el objetivo de cola solo regula el **ritmo de encolado**, no el **ffmpeg concurrente**. La evidencia del 2026-07-24 11:35:32-36 (15 `ffmpeg falló` en 4 segundos, stderr truncado a offsets distintos, cero HTTP 429) es contención local de CPU/IO, no rechazo de la API. Este semáforo desacopla el número de workers de la concurrencia real.

### 11. Duplicate dispatch prevention

- `ConvertAndTranscribeJob` **MUST** implement `ShouldBeUnique` with `uniqueId() = fileId` and `uniqueFor = 900` (paired with `retry_after`).
- The same `file_id` reaches `queues:transcription` through four paths: the tick, `scan-and-submit`, `bulk-dispatch`, and manual send. Uniqueness collapses them to one in-flight job.

### 12. Bounded manual dispatch

- `ScanAndSubmitCommand` **MUST NOT** compute its dispatch cap as `scan_batch × storage_count`.
  - *Motivo*: con 31 storages y `scan_batch=100` eso son 3100 jobs en un bucle apretado, sin pasar por el regulador. Es además la ruta que usa el botón "Escanear storages" de la UI, así que la inundación también ocurría con disparo manual.
- The cap **MUST** be `min(scan_max_dispatch_per_cycle, computeDispatchBatch(Redis::llen('queues:transcription')))`, using the same arithmetic as §3 so the manual path can never exceed the automatic one.
- The browser-side bulk send **MUST** bound its parallel requests to `ui_max_parallel_sends`, and `POST /ia/api-transcriptor/transcribe/{fileId}` **MUST** carry a `throttle` middleware as defence in depth. That endpoint runs ffmpeg + POST synchronously inside php-fpm.

### 13. Runtime observability and control surface

- The system **SHALL** expose, under the existing `['auth','admin']` + `prefix('ia')` route group:
  - `GET  /ia/api-transcriptor/settings` — effective values with origin, plus live runtime (queue depth vs target, active workers vs target, per-state counts, the batch the regulator would compute right now)
  - `POST /ia/api-transcriptor/settings` and `POST /ia/api-transcriptor/settings/reset`
  - `POST /ia/api-transcriptor/settings/run-tick` — run or dry-run the scheduled task on demand
- Writes **MUST** be validated from the same schema constant that renders the form, and **MUST** be audit-logged with the acting admin's id taken from `session('user')` (this project uses session-based auth, never `auth()->user()`).
- Cross-field invariants **MUST** be enforced on write: `min_batch ≤ max_batch`, `worker_min ≤ worker_max`, `runway ≤ target_redis_queue`, `submit_timeout < ConvertAndTranscribeJob::$timeout`.
- A companion command `transcription:config {--json} {--set=*} {--reset=*}` **SHALL** provide the same surface from the CLI, as the operator escape hatch when the UI is unavailable.

## REMOVED Requirements

None. No existing capability is dropped.

---

## Acceptance Criteria

Superseding criteria 3 and 4 of the original spec, and adding to the rest:

1. With the Redis queue artificially above target, `php artisan transcription:tick --dry-run` prints `DISPATCH: skip`. Before this change it printed `batch_computed=10`.
2. `systemctl list-units 'tcloud-transcription-*' --all` shows exactly `workers_target` active `tcloud-transcription-batch-N.service` units and **zero** active `tcloud-transcription-worker@N.service` instances, enforced automatically by the tuner rather than by convention.
3. A back-to-back `transcription:tune --apply --json` emits empty `started`, `stopped` and `stopped_orphans`.
4. Changing `target_redis_queue` from the UI is reflected in the **next scheduled tick's** log context, with no systemd restart. This proves the long-lived CLI process observes the change.
5. With `dispatch_paused` on, the tick logs discovery-complete + dispatch-skipped, and the UI's dispatch endpoints return HTTP 423.
6. With `inflight_max=2` and 11 workers, `ps aux | grep -c ffmpeg` never exceeds 2 while a 50-job backlog drains, and no job exhausts `retryUntil`.
7. Dispatching the same `file_id` from two paths within 900 s grows `queues:transcription` by 1, not 2.
8. `transcription:scan-and-submit` without `--no-dispatch` grows the queue by at most `scan_max_dispatch_per_cycle`, not by `scan_batch × storage_count`.
9. Selecting 50 files in the UI's bulk send keeps concurrent ffmpeg processes at approximately `ui_max_parallel_sends`, not 50.
10. `grep -rn "config('transcriptor\." app/ resources/` returns zero hits outside `TranscriptorSettings.php`.
11. `DELETE FROM system_settings WHERE key LIKE 'transcriptor.%'` restores the exact pre-change runtime behavior.
