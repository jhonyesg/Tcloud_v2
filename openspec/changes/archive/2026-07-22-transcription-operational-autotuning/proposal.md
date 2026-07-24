# Operational Auto-tuning for Transcription Module

> Status: **draft** (proposal reviewed 2026-07-22; revised after user feedback at 11:11 local). All decisions captured here come from the analysis session with the user. Revisions: removed the HH:17/HH:22 fixed-window schedule; consolidated to a single every-2-minutes cycle that does discovery + dispatch (regulator-bounded) for the current day only.

## Why

The transcription module (Tcloud) currently runs in three conflicting modes at once, producing "hyperventilation" on the transcriptor server (`192.168.0.138:9000`) and unpredictable latency on newly created media. Three concrete problems motivate this change:

### 1. No enforced load envelope against the transcriptor API

Direct probe of `/api/stats` on 2026-07-22 10:24 shows:

```json
{
  "jobs": { "queued": 10, "processing": 4, "done": 27248, "error": 52, "dead": 77 },
  "workers": 2,
  "gpu": { "vram_total_gb": 21.46, "vram_pct": 62, "util_pct": 100 }
}
```

- The transcriptor has **2 GPU workers** consuming jobs at ~4–8 jobs/min sustained.
- Its internal queue (`jobs.queued`) was observed fluctuating between 10 and 32 in the last hour.
- The Tcloud side has **no auto-tuner** of its own dispatch rate: it enqueues 100 jobs every ~3 minutes (legacy cron still firing), leading to bursts that grow `jobs.queued` toward the transcriptor's saturation point.

Today the Tcloud queue Redis (`queues:transcription`) can reach into the hundreds between batches while the transcriptor is throttling on its side. The user wants Tcloud to act as a **flow regulator**: keep Tcloud's pending export queue near **140 items** so the transcriptor is **never idle** (avoid latency) but **never overrun** (avoid GPU pressure + RAM swap).

### 2. Two competing cron engines and over-engineered scheduling

On 2026-07-22 syslog review, the legacy `*/3` cron interleaved with the user's proposed HH:17/HH:22 cadence:

```
Jul 22 09:12:01 root CMD (... transcription_enqueue_batch.php 100)   (legacy */3)
Jul 22 09:15:01 ... (legacy */3)
Jul 22 09:36:01 ... (legacy */3)
Jul 22 10:03:01 ... (legacy */3)
Jul 22 10:15:01 ... (cron expression: 17,22 cron, NEW format)
Jul 22 10:18:01 ... (cron expression: 17,22 cron, NEW format)
```

The user feedback on 2026-07-22 at 11:11 confirmed that having fixed minute-mark windows (HH:17/HH:22) was over-engineered. The actual requirement is simpler:

> "Quitemos el de 15 y 21 minutos y coloquemos a lo mejor una ejecución cada dos minutos. Y quitamos el de 3 mejor. Para que cada dos minutos estén viendo información. Que creo que si se exovió algo se detecta y se envía. Que sea el día actual."

In English: drop the HH:17/HH:22 fixed-window and the legacy `*/3`; replace with **one execution every 2 minutes**, processing **only the current day's files**, with the **regulator still bound by the 140-pending target**. The discovery and enqueue phases collapse into a single cycle because the user wants immediate feedback ("if something popped up, it gets detected and sent").

### 3. No documentation captures the operational decisions

- There is **no `docs/transcription.md`**, no `AGENTS.md`, no `README.md` for the module.
- The only specification of operational behavior lives in code comments (`TranscriptionSubmitService` doc block, `routes/console.php` inline comments) which are easy to lose and impossible to diff against.
- Future changes (e.g. switching to `supervisord`, changing the transcriptor's GPU count, supporting new storages) have no baseline to reason against.

## What Changes

### A. Single source of truth for transcription automation

Make `routes/console.php` the **only** place where transcription cron timings are declared. Remove the `*/3` legacy entry and the HH:17/HH:22 fixed-window entry. Replace both with a single `*/2 * * * *` cadence owned by Laravel Scheduler.

### B. Collapse discovery + dispatch into one regulated cycle

The previous design separated "scan + create pending" (`scan-and-submit --no-dispatch`) from "enqueue pending" (cron SO `17,22`). This separation was based on the assumption that the regulator needed time to "fill up" between cycles. After the user's feedback we drop that assumption: one cycle = one scan + one regulator-bounded dispatch. **No cron SO entry exists anymore** for `transcription_enqueue_batch.php`; the entire flow lives inside `routes/console.php`.

The regulator formula (target=140, min=10, max=200) survives unchanged. The user's "if something popped up, it gets detected and sent" aligns naturally: every 2 minutes, scan the disk for new files of the current day, enqueue up to the regulator-allowed batch into the Redis worker queue.

### C. Same-day scope only

The scanner and the dispatch both filter to `created_at >= startOfDay()` (current local day). Files from previous days that are still `state=pending` are **out of scope** for this automated flow. They remain available for manual recovery via `diagnose-pending` or the UI bulk-dispatch endpoint. This keeps the cycle fast and predictable.

### D. Auto-tuned batch size with respect to the 140-pending target

The `scripts/transcription_enqueue_batch.php` script's static formula `batch = clamp(medios * 3, 50, 200)` is replaced with a **dynamic regulator**:

```
target_redis_queue = 140    # user-defined upper bound for "ready to dispatch" backlog
current_redis_queue = llen(queues:transcription)
local_pending_today = Transcription::where(state=pending && job_id=null && created_at >= today)->count()
batch_size = clamp(target_redis_queue - current_redis_queue + 5, 10, 200)
```

Where the `+5` is a minimum runway so the queue never bottoms out, and the formula naturally:

- Sends **0** when `current_redis_queue` is already >= 140 (prevents bursts).
- Sends a small **replenishment** when the queue has drained close to target.
- Sends the maximum when the Redis queue is empty AND we have many pending (e.g. catch-up after an outage).

### E. Make `transcription:tune` an official, idempotent auto-scaler

The current `TranscriptionTuneCommand` (created 2026-07-22) computes `workers_objetivo = clamp(medios_total / 6, 3, 12)` and toggles systemd units `tcloud-transcription-batch-{1..12}.service`. Promote it to:

- Be considered the **only** worker-pool manager (no `nohup`-ed workers, no other systemd templates).
- Be made idempotent: running it back-to-back with the same storage set produces no churn.
- Emit a clear structured log line so its decisions are diff-able across runs.

### F. OpenSpec-bound change

This change is itself the documentation: `openspec/changes/2026-07-22-transcription-operational-autotuning/proposal.md` (this file) and `tasks.md`. Future operational changes must add new changes under `openspec/changes/` with their own proposal + tasks before touching `routes/console.php` or the systemd units.

## Capabilities

### New Capability

- **`transcription-orchestrator-runtime`** — formalizes the runtime contract: every 2 minutes scan + regulator-bounded dispatch, current-day only. Single source of truth lives in this spec.

### Modified Capabilities

- `transcription-api-orchestrator` — adds the dynamic batch regulator and the "no flooding" contract. Removes references to HH:17/HH:22 fixed windows.
- `transcription-disk-scanner` — adds explicit "current-day only" filter for the automated cycle.

### Removed

None. This change modifies operations only, not data shapes.

## Non-Goals

- **No inotify / predictive scanner.** Discovery stays on `scandir` polling every 2 minutes.
- **No backfill of legacy or stale `state=pending` rows from previous days** in the automated cycle. Manual recovery only.
- **No GraphQL / webhook listening.** The transcriptor still operates push-free; only polling of `GET /v1/jobs/{id}` happens on our side.
- **No enabling/disabling observer on `StorageProvider::saved`** — the auto-tuner (`transcription:tune`) is sufficient at 5-min cadence.
- **No `TRANSCRIPTOR_WEBHOOK_*` runtime path.** Any env vars present in `.env` referencing webhooks are **legacy documentation** only.
- **No migration of legacy `transcription-high` Redis queue entries** in this change (handled by the previous `2026-07-18-transcriptor-storage-scope-and-dedup` change).

## Impact

### Configuration

- `routes/console.php`: one entry per concern, all unified on `*/2 * * * *` for the scan+enqueue cycle. A unified command (`transcription:tick`) handles both phases to keep the schedule declarative.
- `scripts/transcription_enqueue_batch.php`: either deleted (if `transcription:tick` absorbs it) or kept as a manual-only tool with a banner saying "do not schedule, use transcription:tick".
- `config/transcriptor.php`: add **`target_redis_queue`**, **`min_batch`**, **`max_batch`** from env with sensible defaults (140, 10, 200). Add **`scope`** with default `current_day` to formalize the today-only filter.
- `/etc/systemd/system/tcloud-transcription-batch-{1..12}.service`: the only worker pool (already in place; no edits expected).
- `crontab root`: only two entries — Laravel `schedule:run` every minute, and nothing else for transcription. The `17,22` and any `*/3` entries are removed.

### Backwards compatibility

- **Read paths unchanged.** All queue entries from current producers (`ConvertAndTranscribeJob::dispatch`) are identical in shape.
- **Write paths unchanged.** The `Transcription` table is mutated in the same fields and states. The only data-shape constraint is that `created_at >= today` for automated enqueue.
- **API surface to the transcriptor (`http://192.168.0.138:9000`) unchanged.** All calls (`POST /v1/transcribe`, `GET /v1/jobs/{id}`, `GET /v1/jobs/{id}/srt`) continue to use the same headers and bodies.

### Risk

- **Mis-sized regulator formula**: if `target_redis_queue=140` is too low, the transcriptor may sit idle during quiet hours; if too high, the transcriptor's GPU pressure returns. Mitigation: tunable via `.env`, plus a `dry-run` mode in the script that prints the proposed batch size without dispatching.
- **Discovery/enqueue in the same pass**: a slow scan could starve the dispatch step. Mitigation: the scan only looks at today's folder and is bounded by `scan_batch` (100 per storage). On 2026-07-22 the scan completed in ~90s, well within the 2-minute budget.
- **Same-day-only filter may hide real backlog growth**: if a day's backlog grows past the regulator handle (140), the next cycle simply skips it. Mitigation: surface this in `diagnose-pending` and the existing UI badge.
