## Context

The API Transcriptor module's Trabajos → Pendientes view (`app/resources/views/ia/api-transcriptor/index.blade.php`) currently exposes:

- **Per-row "Enviar ahora"** at line 642 (`index.blade.php:642`) calling `POST /jobs/{id}/dispatch-now` (controller: `ApiTranscriptorController::dispatchNow`, `ApiTranscriptorController.php:723`). The endpoint refuses with `409` if state ∉ `{queued, processing}` and returns `already_submitted: true` (no-op) if `job_id` is set.
- **Bulk action bar** at line 525 added by the prior change `jobs-pending-bulk-dispatch`. The filter `dispatchableJobs()` returns `(this.jobs || []).filter(j => j.state === 'queued' && !j.job_id)` (line 1168) — **0 of the user's 200 stuck jobs match this**.
- **Top-right "Procesar lote"** at line 76-82 opens a modal that runs the existing `transcription:process-batch` background command, which scans storages for files-without-Transcription-row. It does not touch the 200 stuck jobs either.

Upstream has `syncFromUpstream(Transcription $t)` (`ApiTranscriptorController.php:836`) that already does everything needed for a stuck-job refresh: queries the upstream API for `$t->job_id`, updates local state, and on `done` calls `processDone()` (which downloads SRT and creates segments + triggers keyword matching). Today this is only called from the GET endpoint `jobStatus()` (`ApiTranscriptorController.php:799`) for the per-row progress modal polling.

The user's 200 jobs are exactly the population `syncFromUpstream()` was built for. We just need to expose it as a POST endpoint (so the Alpine bulk engine can fan it out via `Promise.allSettled`) and broaden the dispatchable filter so the bar surfaces them.

## Goals / Non-Goals

**Goals:**
- Make the bulk action bar in Pendientes visible and actionable for the 200 stuck jobs the user has today.
- Cover both code paths in one bulk action: dispatch new (`!job_id`) and refresh stuck (`job_id`).
- Preserve SRT download + segment population + keyword alerts on the refresh path so a refreshed-to-done job is a complete transcription.
- Rename the top-right "Procesar lote" button to "Escanear storages" with copy that matches what it actually does (scans storages for files-without-Transcription-row), so the user understands the distinction.

**Non-Goals:**
- No changes to the `transcription:scan-stale` cron, scanner cadence, or `transcription:process-batch` command semantics.
- No client-side throttling or backoff — same browser-native parallelism as today.
- No new model fields, no migration, no new service class.
- No spec changes to `transcription-api-orchestrator` (its requirements are unaffected).

## Decisions

1. **New endpoint `POST /ia/api-transcriptor/jobs/{id}/refresh-status`** at `app/routes/web.php` (added next to `/dispatch-now` at line 170). Body: none. Response: same shape as the existing GET `/jobs/{id}/status` (id, state, job_id, etc.). Implementation: thin wrapper that loads `Transcription`, calls `syncFromUpstream()`, returns `$job->refresh()` + segments_count. POST (not GET) because it's a write side-effect on local state — same convention as `/dispatch-now`. Same auth/admin middleware as the rest of the `Route::prefix('ia')` group (line ~167).

2. **Loosen `dispatchableJobs()` filter** at `index.blade.php:1168`:
   ```js
   return (this.jobs || []).filter(j => j.state === 'queued' || j.state === 'processing');
   ```
   No `!j.job_id` clause. The downstream engine picks the right endpoint per row.

3. **`bulkDispatchPending()` per-job routing** at `index.blade.php:1541`. Build target list with both `id` and `job_id` (not just `id`), then:
   - `j.job_id` truthy → `POST /jobs/{j.id}/refresh-status`
   - else → `POST /jobs/{j.id}/dispatch-now`
   Same headers (CSRF), same `Promise.allSettled` fan-out, no concurrency cap.

4. **Response classification** in `bulkDispatchPending`:
   - `refresh-status` returns 200 and `state === 'done'` → `refreshedDone` (segments populated, job moves to Completados after `load()`)
   - `refresh-status` returns 200 and `state === 'processing'` → `refreshedProcessing`
   - `refresh-status` returns 200 and `state ∈ {error, dead}` → `refreshedError`
   - `dispatch-now` returns 200 without `already_submitted` → `sentNew`
   - `dispatch-now` returns 200 with `already_submitted: true` → `skipped` (count toward informational total)
   - any non-2xx or exception → `errors`
   Aggregate into `bulkDispatchResult = { sentNew, refreshedDone, refreshedProcessing, refreshedError, errors }`. Banner reads three lines: "N despachados · M refrescados (X a done, Y siguen) · Z errores".

5. **Per-row button label swap** at `index.blade.php:642`. The existing button has `x-show="jobsSubTab === 'pending' && job.state === 'queued' && !job.job_id"`. Change to:
   - Show when `state ∈ {queued, processing}`
   - Label text via `x-text`: `job.job_id ? 'Refrescar estado' : 'Enviar ahora'`
   - Click handler via `:title`: `job.job_id ? 'Consultar upstream y actualizar estado' : 'Enviar al transcriptor (ffmpeg + POST)'`
   - On click, branch: `job.job_id` → `POST /jobs/{id}/refresh-status`, else → existing `dispatchJobNow(job)` path.

6. **Top-right button rename** at `index.blade.php:76-82`. The button keeps `openBatchModal()`. Change:
   - Label: "Procesar lote" → "Escanear storages"
   - `title` attribute: "Escanear storages y crear jobs para archivos sin transcripción"
   - Modal title (search for the modal that opens on `x-show="showBatchModal"`): "Escanear storages" + descriptive subtitle
   - Modal copy for `batchAlerts` checkbox / batch size input: clarify that this scans storages (does NOT touch the 199 rows already in the Pendientes table — use the barrita for those).

7. **Selection mode checkbox** at `index.blade.php:592-600` (header) and line 613 (row) — keep the `selectJobMode` toggle but enable per-row checkbox whenever `state ∈ {queued, processing}` (drop the `!job.job_id` requirement). Rows already excluded by `isDispatchable()` (= `state === 'queued' && !job.job_id`) become selectable.

## Risks / Trade-offs

- **Calling `/refresh-status` on a job the user just dispatched seconds ago**: harmless — upstream will respond with `queued/processing` and we update accordingly. At worst we make one extra GET to the upstream API.
- **`/refresh-status` on a job whose `job_id` is stale on the upstream side** (the upstream dropped it, or the server restarted): `syncFromUpstream()` already catches the exception and logs it; the response will report `errors`. Banner surfaces it.
- **Large bulk refresh burst (200 concurrent GETs to upstream)**: same browser connection cap (~6 per origin over HTTP/1.1) as the prior change. Upstream is a LAN service with a GPU; 200 quick GETs are negligible compared to ffmpeg loads. Acceptable.
- **`processDone()` fires KeywordMatcher on each refreshed-done job** (existing behavior in `TranscriptionProcessor::processDone`). For 100+ recovered transcriptions this could trigger many keyword-match runs in sequence — but it's a one-time recovery cost, not steady-state. Acceptable.
- **User confusion between the two affordances**: even after renaming, "Procesar pendientes" (barrita, fixes existing rows) vs "Escanear storages" (top-right, picks up new files) is a real distinction. Mitigation: modal copy + tooltip on the top-right button explicitly say what it does and does NOT touch.

## Migration Plan

- No data or schema changes. Roll-forward: deploy view + new route + new controller method.
- Rollback: revert view + remove the new route + remove the new controller method. The old `!j.job_id` filter and dispatch-only behavior returns; the 200 stuck jobs again wait for `scan-stale`.
- Browser side: hard refresh required only if the user has the page open across deploy (Alpine state from old view will not know about `refreshedDone` etc.). The init path does not error on unknown fields.