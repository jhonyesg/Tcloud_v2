## Why

The `jobs-pending-bulk-dispatch` change (complete) shipped a bulk action that filters by `state === 'queued' && !job_id`. In production this filter matches **zero** of the user's actual 200 stuck jobs: all have `job_id` (already submitted to the upstream transcriptor API) but the webhook callback was lost, so they remain in `queued` forever locally. The bulk button is invisible because the filter excludes them. The user needs a single "Procesar pendientes" action that handles both new dispatches (no `job_id`) and stuck-job refresh (has `job_id`), so each File ends up with a valid transcription.

## What Changes

- **Loosen the `dispatchableJobs` filter** from `queued && !job_id` to `state ∈ {queued, processing}` so stuck jobs become visible in the action bar.
- **Route per-job to the correct endpoint** in `bulkDispatchPending`:
  - `!job_id` → `POST /jobs/{id}/dispatch-now` (existing — ffmpeg + POST externo).
  - `job_id` → `POST /jobs/{id}/refresh-status` (**new** — wraps `syncFromUpstream()` to poll upstream; if upstream returns `done`, the existing `processDone()` path downloads the SRT and populates segments).
- **New endpoint** `POST /ia/api-transcriptor/jobs/{id}/refresh-status` that calls `syncFromUpstream()` and returns the updated state. No new model fields, no migration.
- **Banner shows 4 counters**: newly dispatched / refreshed-to-done / refreshed-still-processing-or-error / errors. Per-row "Enviar ahora" button changes label to "Refrescar estado" when the row already has `job_id`.
- **Rename top-right button** "Procesar lote" → "Escanear storages" with copy clarifying it scans storages for files not yet in the `transcriptions` table (the existing `transcription:process-batch` console command). Keep the endpoint, controller method, command, and modal — only rename the UI affordance and update modal copy.

## Capabilities

### New Capabilities

- `jobs-stuck-refresh-bulk`: per-row and bulk action that polls the upstream transcriptor API for jobs that have `job_id` but remained `queued/processing` locally, and updates local state (including downloading SRT and populating segments when upstream returns `done`).

### Modified Capabilities

- `jobs-pending-bulk-dispatch`: the dispatchable filter becomes `state ∈ {queued, processing}` (no `job_id` requirement), and the bulk engine routes per-job to either `/dispatch-now` or `/refresh-status`. The banner now distinguishes dispatched vs refreshed outcomes.

## Impact

- **View**: `app/resources/views/ia/api-transcriptor/index.blade.php` (filter + bulk engine rewrite + per-row button label + modal copy + top-right button label).
- **Controller**: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (new `refreshStatus()` method; `processBatch()` unchanged, just renamed in the UI).
- **Routes**: `app/routes/web.php` (one new POST route for `/jobs/{id}/refresh-status`).
- **Services**: reuses existing `TranscriptorApiClient::getJob()` and `TranscriptionProcessor::processDone()` — no new services.
- **Migration**: none.

## Non-goals

- No changes to the `transcription:scan-stale` cron — it remains the 30-minute backup for unassisted recovery.
- No changes to `dispatchNow`, `jobStatus` (single-job polling), `retry`, `reprocess`, or cancel endpoints.
- No changes to the scanner cadence (`scan_batch`, `scan_new`) or to `transcription:process-batch` semantics — it still picks files-without-Transcription-row.
- No client-side concurrency cap, exponential backoff, or rate limiting — same browser-native parallelism as the prior change.
- No new spec for `transcription-api-orchestrator` — that capability's requirements are unchanged.