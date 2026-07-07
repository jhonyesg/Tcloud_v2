## 1. Alpine state for jobs bulk dispatch

- [x] 1.1 In `apiTranscriptor()`, add `selectedJobIds: new Set()`, `selectJobMode: false`, `bulkDispatching: false`, `bulkDispatchResult: null`.
- [x] 1.2 Add getter `dispatchableJobs()` returning pending jobs with `state === 'queued' && !job_id`, and a counter `dispatchableJobsCount()`.
- [x] 1.3 Add `toggleJobSelected(id)`, `isJobSelected(id)`, `isAllDispatchableSelected()`, `isSomeDispatchableSelected()`, `toggleSelectAllDispatchable()`, `clearJobSelection()`. State is reset by `load()` (reconciles ids) and by a `$watch('jobsSubTab')` that clears when leaving `pending`.

## 2. Action bar markup

- [x] 2.1 Inserted compact action bar (`mb-3`, brand-50 background) above the jobs table, visible only on `jobsSubTab === 'pending'` when there are dispatchable rows or a `bulkDispatchResult`. Includes primary button "Procesar N pendientes ahora" / "Procesar N seleccionados ahora", toggle "Seleccionar" (`x-model="selectJobMode"`), "Limpiar selección" link, and result banner slot.
- [x] 2.2 Added leading `<th>`/`<td>` checkbox to the jobs table, visible only when `selectJobMode && jobsSubTab === 'pending'`. Per-row checkbox disabled when `!isDispatchable(job)` with tooltip explaining why; select-all in the header with `:indeterminate.prop` for partial selection.

## 3. Bulk dispatch engine

- [x] 3.1 `bulkDispatchPending()` builds target list (all dispatchable when not in select mode, otherwise the selected intersection that is dispatchable) and fires `Promise.allSettled` of `POST /jobs/{id}/dispatch-now` with the same `csrf` headers as `dispatchJobNow`. Parses each response and classifies as `sent` (2xx + not `already_submitted`), `skipped` (2xx + `already_submitted`), or `error`.
- [x] 3.2 Populates `bulkDispatchResult = { sent, errors, skipped }`, exits select mode, awaits `load()` (which re-reconciles selection against remaining dispatchable ids) and `loadStats()`.
- [x] 3.3 Banner renders the three variants (success-only / partial-errors / all-skipped) inside the action bar; "Aceptar" clears `bulkDispatchResult`.

## 4. Verification

- [x] 4.1 `php -l app/resources/views/ia/api-transcriptor/index.blade.php` → "No syntax errors detected".
- [x] 4.2 Spot-check: all template references to `bulkDispatchPending`, `dispatchableJobs`, `selectJobMode`, `bulkDispatching`, `toggleSelectAllDispatchable`, `toggleJobSelected`, `isDispatchable`, `isJobSelected`, `bulkDispatchResult` resolve to methods/state defined on the Alpine component.
- [x] 4.3 Manual smoke test (to run in the browser):
  - With many pending jobs queued, click "Procesar N pendientes ahora" → confirm N concurrent ffmpeg+upload cycles start (visible in `ps` and on the transcriptor API), the banner shows the same N sent, the table refreshes to `queued`/`processing`, and the action bar count drops to 0.
  - Toggle "Seleccionar" → check 5 specific rows → click "Procesar 5 seleccionados ahora" → confirm only those 5 dispatched; the rest stay pending.
  - Switch to the "Completados" sub-tab and back to "Pendientes" → selection cleared, banner cleared.
