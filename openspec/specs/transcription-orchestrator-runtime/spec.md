# Spec — Transcription Orchestrator Runtime

> Single source of truth for the runtime behavior of the transcription module: every-2-minutes scan + regulator-bounded dispatch, current-day only.

## ADDED Requirements

### 1. Unified Tick Phase

- The system **SHALL** run `transcription:tick` on a fixed `*/2 * * * *` cadence via Laravel Scheduler.
- Each invocation **MUST** complete both phases within `withoutOverlapping(150)` seconds (a 30s safety margin over the 2-minute cadence).
- The tick **MUST** be the only scheduled entry point for transcription work. There is no separate "discovery" or "enqueue" schedule; both phases live inside `TranscriptionTickCommand::handle()`.

### 2. Phase 1 — Discovery (current-day only)

- The tick **SHALL** invoke `transcription:scan-and-submit --no-dispatch` programmatically in Phase 1.
- The scanner **MUST** only consider files whose `mtime` falls within `now()->startOfDay()` to `now()`. This is enforced by `--days=0` in `ScanAndSubmitCommand` and verified by reading L24.
- The discovery phase **MUST NOT** push jobs to the Redis queue `queues:transcription`. Its sole responsibility is to keep the `transcriptions` table populated with `state=pending, job_id=null` rows for newly-discovered media files of the current day.

### 3. Phase 2 — Regulator dispatch (current-day only)

- After Phase 1, the tick **MUST** compute its dispatch batch via the regulator formula:
  ```
  target = (int) config('transcriptor.target_redis_queue')          # default 140
  min    = (int) config('transcriptor.min_batch')                   # default 10
  max    = (int) config('transcriptor.max_batch')                   # default 200
  current = (int) Redis::llen('queues:transcription')
  batch = max($min, min($max, $target - $current + 5));
  ```
- If the regulator computes `batch <= 0`, the tick **SHALL** log "queue at target, skip dispatch" and return `Command::SUCCESS` **without** touching Redis.
- Otherwise, the tick **SHALL** dispatch `ConvertAndTranscribeJob` for up to `batch` rows where:
  ```
  state = 'pending' AND job_id IS NULL AND created_at >= now()->startOfDay()
  ORDER BY created_at ASC
  ```
  ordered oldest-first within the day for fairness.
- The dispatch **MUST** use the existing `ConvertAndTranscribeJob::dispatch($fileId, true)` producer; no new job class is introduced.

### 4. Same-day scope — explicit non-goal

- The tick **SHALL NOT** dispatch files whose `created_at` predates `now()->startOfDay()`.
- Previous-day pending rows **MUST** remain queryable via `transcriptor:diagnose-pending` and re-dispatchable via the existing `POST /api-transcriptor/jobs/bulk-dispatch` UI endpoint.
- This scope is encoded as `TRANSCRIPTOR_SCOPE=current_day` in `.env`. Future expansion to `current_day_plus_one` or `unbounded` is permitted by changing that env, but **MUST** be documented in a new OpenSpec change before touching code.

### 5. Polling Phase (independent of the tick)

- The system **SHALL** run `transcription:poll-results` every minute via Laravel Scheduler, independent of the tick cadence.
- This phase is **NOT** merged into the tick. Polling concerns (re-dispatch stuck rows, fetch SRT) are separate from discovery/dispatch.

### 6. Auto-tuner Phase

- The system **SHALL** run `transcription:tune --apply` every 5 minutes via Laravel Scheduler.
- The tuner **MUST**:
  - Count `StorageProvider::transcriptionEnabled()` rows split by `folder_layout` (`flat` vs `grouped_by_subfolder`).
  - For grouped storages, count the **immediate subdirectories** of `base_path` and add to the mean count.
  - Compute `workers = clamp(medios_total / 6, 3, 12)`.
  - `systemctl enable --now` exactly that number of `tcloud-transcription-batch-N.service` units, and `systemctl stop` the rest.
- The tuner **MUST** be idempotent: a back-to-back run with the same storage count must NOT issue start/stop shell calls for already-correctly-stated services.
- The tuner **SHALL** emit a JSON line per run to `storage/logs/transcription-tune.log` containing `{ts, storages_total, medios_total, workers_target, started[], stopped[]}`.

### 7. Worker Pool Contract

- The system's only worker pool is the set of systemd services `tcloud-transcription-batch-{1..12}.service`, all of which:
  - `ExecStart=/usr/bin/php -d memory_limit=512M artisan queue:work --queue=transcription --tries=3 --timeout=600 --sleep=1`
  - `Restart=always`, `RestartSec=5`
  - Logs appended to `storage/logs/worker-batch-N.log`.
- **No other worker pool** is permitted: no `nohup`-ed manual workers, no `supervisord`-managed workers, no additional systemd templates. Any future pool must add itself as a new capability spec.
- **`tcloud-transcription-batch.service` (legacy singular)** **SHALL** remain `inactive` and its template-style units (`tcloud-transcription-worker@.service`) **SHALL NOT** have enabled instances.

### 8. Single OS Cron, No Transcription Entries

- `/var/spool/cron/crontabs/root` **MUST** contain exactly:
  - One `* * * * *` for `schedule:run` (the Laravel scheduler entry point).
- **No entry** for `transcription_enqueue_batch.php` (the script is manual-only or absorbed into `transcription:tick`).
- `/www/server/cron/*` (panel `aaPanel` scripts) **MUST NOT** invoke `transcription_enqueue_batch.php` directly. If any is found, it is a regression and **MUST** be removed.

### 9. Configuration Surface

- The keys added or extended in `app/config/transcriptor.php` (with env defaults):
  - `target_redis_queue`   ← `TRANSCRIPTOR_TARGET_REDIS_QUEUE`  (default `140`)
  - `min_batch`            ← `TRANSCRIPTOR_MIN_BATCH`           (default `10`)
  - `max_batch`            ← `TRANSCRIPTOR_MAX_BATCH`           (default `200`)
  - `scan_batch` (existing) ← `TRANSCRIPTOR_SCAN_BATCH`           (default `100`)
  - `scan_days_back` (existing) ← `TRANSCRIPTOR_SCAN_DAYS_BACK` (default `0`)
  - `scope` (new)         ← `TRANSCRIPTOR_SCOPE`                (default `current_day`)
- The system **SHALL NOT** introduce a `TRANSCRIPTOR_WEBHOOK_*` runtime path; the transcriptor is **push-free** and any env vars present in `.env` referencing webhooks are **legacy documentation** only and **MUST NOT** be wired.

## MODIFIED Requirements

None. This change only documents existing operational behavior.

## REMOVED Requirements

None. No existing capability is dropped.

---

## Acceptance Criteria

After this change is in production for 30 minutes with steady traffic (~20 files/min):

1. `Redis::llen('queues:transcription')` oscillates in `[120, 160]` consistently.
2. `/api/stats` on the transcriptor reports `jobs.queued` in `[10, 32]` consistently.
3. The schedule's `transcription:tune` log shows `workers_target = 11` (matching the 66-medios equivalent formula) without churn on stable inputs.
4. `systemctl list-units --all tcloud-transcription*` shows exactly 11 `active` `tcloud-transcription-batch-N.service` units and 0 others.
5. `/var/log/syslog` shows NO invocation of `transcription_enqueue_batch.php` (the script is no longer scheduled). The only transcription-related entries are `php artisan schedule:run` running every minute.
6. No job is dispatched directly to `queues:transcription` from anywhere except `transcription:tick`. A `redis-cli MONITOR` run over 10 minutes **MUST NOT** show `LPUSH queues:transcription` from any source other than that command.
7. `Transcription` rows dispatched by `transcription:tick` are limited to `created_at >= now()->startOfDay()`. A previous-day stale pending row remains in the table but **MUST NOT** appear in dispatched IDs over a 30-minute observation.
