# Tasks — 2026-07-22 Transcription Operational Auto-tuning

> Implementation tasks. Items marked `[ ]` are not yet done. Items marked `[x]` are completed.

## 1. New unified tick command

- [ ] 1.1 Create `app/app/Console/Commands/TranscriptionTickCommand.php` with signature `transcription:tick` (no options, or only `--dry-run`).
- [ ] 1.2 The `handle()` method does two phases in one invocation:
  - **Phase 1 — Discovery**: invoke `DiskScannerService` for each `StorageProvider::transcriptionEnabled()`, scoped to `created_at >= now()->startOfDay()`. Reuse the existing `transcription:scan-and-submit --no-dispatch` internals (call the command programmatically).
  - **Phase 2 — Regulator dispatch**: compute `batch = clamp(target - current + 5, min, max)` against `Redis::llen('queues:transcription')` and the target/min/max in `config/transcriptor.php`. If `batch > 0`, dispatch `ConvertAndTranscribeJob` for the first `batch` rows where `state=pending`, `job_id IS NULL`, and `created_at >= today`.
  - **Phase 3 — Stale re-dispatch (optional)**: skipped intentionally for this change; covered by the existing `transcription:poll-results` minute-cadence job.
- [ ] 1.3 Add a `--dry-run` flag that prints both phases' proposed actions (newly discovered files count, batch size, list of `file_id`s to dispatch) without mutating anything.
- [ ] 1.4 Log to `storage/logs/transcription-tick.log`.

## 2. Discovery is current-day only

- [ ] 2.1 Add `scope = env('TRANSCRIPTOR_SCOPE', 'current_day')` to `app/config/transcriptor.php`.
- [ ] 2.2 In `ScanAndSubmitCommand`, **when invoked programmatically by `transcription:tick`**, the `--days=0` default already enforces "today only"; add a `notes` block in the docstring making this explicit. Confirm by reading `ScanAndSubmitCommand.php` L24.
- [ ] 2.3 In `TranscriptionTickCommand`, the dispatch query MUST filter `WHERE created_at >= NOW()::date` (PostgreSQL) or `WHERE created_at >= DATE(NOW())` (PG-compatible).
- [ ] 2.4 Document in PHPDoc that **previous-day pending rows are out of scope** for the automated flow and require manual `transcriptor:diagnose-pending` inspection + `bulkDispatch` from the UI.

## 3. Batch regulator formula in the unified tick

- [ ] 3.1 In `TranscriptionTickCommand`, implement the regulator:
  ```
  target = (int) config('transcriptor.target_redis_queue', 140);
  min    = (int) config('transcriptor.min_batch', 10);
  max    = (int) config('transcriptor.max_batch', 200);
  current = (int) Illuminate\Support\Facades\Redis::llen('queues:transcription');
  batch = max($min, min($max, $target - $current + 5));
  ```
- [ ] 3.2 If `batch <= 0` (queue already at or above target), log "queue at target, skip dispatch" and return `Command::SUCCESS`.
- [ ] 3.3 Dispatch `ConvertAndTranscribeJob::dispatch($fileId, true)` for the first `batch` rows where `state=pending AND job_id IS NULL AND created_at >= today`, ordered by `created_at ASC` (oldest first for fairness within the day).
- [ ] 3.4 Add `--dry-run` short-circuit: print `[SCAN] would discover ~N files, [DISPATCH] would send M jobs to Redis queue (current=X, target=Y)` without side effects.

## 4. Schedule consolidation in `routes/console.php`

- [ ] 4.1 Remove the existing `transcription:scan-and-submit --no-dispatch` `cron('16,21 * * * *')` entry.
- [ ] 4.2 Remove the `transcription:tune --apply` `cron('15,20 * * * *')` entry.
- [ ] 4.3 Add ONE new entry:
  ```php
  Schedule::command('transcription:tick')
      ->everyTwoMinutes()
      ->withoutOverlapping(150)
      ->appendOutputTo(storage_path('logs/transcription-tick.log'));
  ```
- [ ] 4.4 Keep `transcription:poll-results` every minute.
- [ ] 4.5 Keep `transcription:cleanup-tmpfs` hourly.
- [ ] 4.6 Replace `transcription:tune --apply` line with a unified cadence `*/5 * * * *`. **Verify** that running it every 5 minutes is acceptable given systemd's idempotent semantics (it is).
- [ ] 4.7 Each `Schedule::command(...)` block carries a 2–4 line inline comment describing cadence and rationale.

## 5. Cron SO cleanup: no transcription entries

- [ ] 5.1 Verify `/var/spool/cron/crontabs/root` ONLY contains:
  - `* * * * * cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app && php artisan schedule:run >> /dev/null 2>&1`
  - **No entry** for `transcription_enqueue_batch.php` (the script becomes manual-only or is deleted).
- [ ] 5.2 Verify the file `scripts/transcription_enqueue_batch.php` either:
  - Is repurposed as a CLI utility called only by `transcription:tick`, OR
  - Is **deleted** and the script body is moved into the `TranscriptionTickCommand` class.
- [ ] 5.3 Verify `/www/server/cron/*.sh` does NOT invoke the batch script (already audited on 2026-07-22 10:32 — confirmed clean).

## 6. Auto-tuner — official, idempotent, observable

- [ ] 6.1 In `app/app/Console/Commands/TranscriptionTuneCommand.php`, add a `--json` flag that emits the proposed state (storages, medios total, workers target, services to start, services to stop) as a single JSON line.
- [ ] 6.2 Make the start/stop idempotent: skip the `systemctl start` call if the service is already `active`; skip the `systemctl stop` call if already `inactive`.
- [ ] 6.3 Confirm the schedule entry is `cron('*/5 * * * *')` with `appendOutputTo` to `storage/logs/transcription-tune.log`.

## 7. Specs

- [ ] 7.1 Update `openspec/changes/2026-07-22-transcription-operational-autotuning/specs/transcription-orchestrator-runtime/spec.md`:
  - Section "ADDED Requirements" → rewrite requirement #1 (Discovery) and #2 (Batch Regulator Phase) to reflect single every-2-minute cycle.
  - The HH:17/HH:22 requirement (if present in earlier drafts) is **removed**.
- [ ] 7.2 No edits to `transcription-api-orchestrator/spec.md` — the regulator formula lives in the new capability spec.

## 8. Smoke validation

- [ ] 8.1 Run `php artisan schedule:list`. Expected output (only transcription-related):
  ```
  */2 * * * *  php artisan transcription:tick
  */5 * * * *  php artisan transcription:tune --apply
  * * * * *    php artisan transcription:poll-results
  0 * * * *    php artisan transcription:cleanup-tmpfs
  ```
- [ ] 8.2 Run `transcription:tick --dry-run`. Expected output: counts of discovered files and proposed batch size, no Redis write, no BD `Transcription` row insertion.
- [ ] 8.3 Run `transcription:tick` (no flag). Expected output: dispatches the batch (or skips with "queue at target"), writes a line per dispatched `file_id` to the log.
- [ ] 8.4 Wait 4 minutes (`*/2` × 2 cycles). Check `storage/logs/transcription-tick.log` has 2 invocations. Confirm `Redis::llen('queues:transcription')` did not exceed 200.
- [ ] 8.5 Probe `/api/stats` on the transcriptor; `jobs.queued` should stay in `[10, 32]` over a 30-minute window.
- [ ] 8.6 Confirm `transcription_enqueue_batch.php` does NOT appear in any `crontab -l`, `/etc/cron.d/*`, or `/www/server/cron/*`.

## 9. Rollback notes

- If the regulator misbehaves: set `TRANSCRIPTOR_TARGET_REDIS_QUEUE=0` in `.env` to disable the regulator (reverts to static behavior) — **tunable mid-flight without code changes**.
- If the cycle starves: bump back to `*/1 * * * *` in `routes/console.php` only.
- If the same-day scope hides a real backlog: temporarily delete `transcription:tick` from the schedule and restore `transcription:scan-and-submit` + the batch cron SO entries (they are still documented in git history).
