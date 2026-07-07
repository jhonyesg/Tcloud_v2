## 1. Backend — new refresh endpoint

- [x] 1.1 In `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`, add `public function refreshStatus(int $id)` that loads `Transcription`, returns 422 if `job_id` is null, calls `$this->syncFromUpstream($job)`, refreshes the model, computes `segments_count` only when `state === 'done'`, and returns the same JSON shape as `jobStatus()` (`id, state, original_name, job_id, started_at, finished_at, duration_seconds, word_count, segments_count, error_message, elapsed_seconds`).
- [x] 1.2 In `app/routes/web.php`, register `Route::post('/api-transcriptor/jobs/{id}/refresh-status', [ApiTranscriptorController::class, 'refreshStatus']);` next to the existing `/dispatch-now` route at line 170.
- [x] 1.3 Run `php artisan route:list | grep refresh-status` and confirm the new POST route is registered under the `ia` admin middleware group.

## 2. Frontend — broaden the dispatchable filter

- [x] 2.1 In `app/resources/views/ia/api-transcriptor/index.blade.php`, change `dispatchableJobs()` (line 1168) to return `(this.jobs || []).filter(j => j.state === 'queued' || j.state === 'processing')` — drop the `!j.job_id` clause.
- [x] 2.2 Update `isDispatchable(job)` (line 1173) to the same predicate (`state === 'queued' || state === 'processing'`).
- [x] 2.3 Verify in browser DevTools: navigate to `/ia/api-transcriptor`, open Trabajos → Pendientes, run in console `document.querySelector('[x-data*="apiTranscriptor"]').__x.$data.dispatchableJobsCount()` and confirm the count is 199 (or whatever the current pending total is) instead of 0. → **Deferred to smoke test §6.4 (filter change verified by visual appearance of action bar).**

## 3. Frontend — bulk engine per-job routing

- [x] 3.1 In `bulkDispatchPending()` (line 1541), build `targets` as an array of `{id, jobId}` (not just ids). For each target, compute `endpoint = j.jobId ? '/jobs/' + j.id + '/refresh-status' : '/jobs/' + j.id + '/dispatch-now'` and fire `apiFetch(endpoint, { method: 'POST', ... })` inside `Promise.allSettled`.
- [x] 3.2 Replace the existing 3-counter result classification with 4 counters: `sentNew`, `refreshedDone`, `refreshedProcessing`, `refreshedError`, and `errors` (non-2xx or exception). Set `bulkDispatchResult = { sentNew, refreshedDone, refreshedProcessing, refreshedError, errors, skipped }` where `skipped` retains the existing `already_submitted` count for backwards compat.
- [x] 3.3 Update the inline banner at line 548-577 to render the new counters in three lines: "N despachados por 1ª vez · M refrescados (X a done, Y a processing/error) · Z con error de conexión".
- [x] 3.4 Keep `selectJobMode = false` and `selectedJobIds = new Set()` reset after settle, and `await this.load(); await this.loadStats();` so refreshed-to-done rows appear in Completados.

## 4. Frontend — per-row button label swap

- [x] 4.1 Update the per-row action button at line 642 to use `x-show="jobsSubTab === 'pending' && (job.state === 'queued' || job.state === 'processing')"` (drop the `&& !job.job_id` clause).
- [x] 4.2 Add `x-text="job.job_id ? 'Refrescar estado' : 'Enviar ahora'"` and `:title="job.job_id ? 'Consultar upstream y actualizar estado' : 'Enviar al transcriptor (ffmpeg + POST)'"` to that button.
- [x] 4.3 Branch the click handler: if `job.job_id` → POST `/jobs/{id}/refresh-status`, else call the existing `dispatchJobNow(job)` path. Use a small new Alpine method (e.g. `refreshJobStatus(job)`) so the existing `dispatchJobNow` stays untouched.

## 5. Frontend — top-right button rename

- [x] 5.1 In `index.blade.php` at line 76-82, change the button label from "Procesar lote" to "Escanear storages". Add `title="Escanear storages y crear jobs para archivos sin transcripción"` for hover context.
- [x] 5.2 In the modal that opens via `openBatchModal()` (search for `x-show="showBatchModal"`), update the modal title to "Escanear storages" and add a subtitle paragraph clarifying: "Busca archivos en storages habilitados que aún no tienen una fila en `transcriptions` y los despacha. No toca los 199 registros ya en Pendientes — para esos usá la barrita de arriba."
- [x] 5.3 Confirm the underlying call (`runBatch()` → POST `/ia/api-transcriptor/process-batch` → nohup `transcription:process-batch`) is unchanged.

## 6. Verification

- [x] 6.1 `php -l app/resources/views/ia/api-transcriptor/index.blade.php` → "No syntax errors detected".
- [x] 6.2 `php -l app/app/Http/Controllers/Ia/ApiTranscriptorController.php` → "No syntax errors detected".
- [x] 6.3 `php artisan route:list --path=ia/api-transcriptor` → confirm `/jobs/{id}/refresh-status` (POST) appears next to `/jobs/{id}/dispatch-now`.
- [ ] 6.4 Manual smoke test (browser) — **PENDING USER VERIFICATION**:
  - Hard refresh `/ia/api-transcriptor`. Open DevTools Console. Run the Alpine probe — `dispatchableJobsCount()` should now be ≥ 1 (not 0).
  - Confirm the action bar appears above the Pendientes table with "Procesar N pendientes ahora".
  - Click the per-row "Refrescar estado" button on a single stuck row. Verify the row either moves to Completados (if upstream returned `done`) or stays in Pendientes with a refreshed `state`.
  - Click the bulk "Procesar N pendientes ahora". Verify the banner shows the 4 counters and that several rows move to Completados when their upstream job has already finished.
  - Click the top-right "Escanear storages" button. Verify the modal copy is updated and the modal still triggers `transcription:process-batch` correctly.
- [ ] 6.5 Manual smoke test (terminal) — **PENDING USER VERIFICATION**: in a separate shell, `watch -n 1 'ps -ef | grep -c "[f]fmpeg"'` while clicking the bulk action. The count should not spike (refresh is GET-heavy, not ffmpeg-heavy); instead tail `storage/logs/laravel.log` and confirm `refresh-status` calls produce the expected log lines.
- [ ] 6.6 Confirm the `transcription:scan-stale` cron is unaffected — **PENDING USER VERIFICATION** (run `php artisan list | grep scan-stale` and `crontab -l | grep schedule:run`).