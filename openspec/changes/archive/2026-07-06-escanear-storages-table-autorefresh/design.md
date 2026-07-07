## Context

The "Escanear storages" affordance lives in `app/resources/views/ia/api-transcriptor/index.blade.php`. It triggers a background `transcription:process-batch` console command via `POST /ia/api-transcriptor/process-batch` and tracks progress with `setInterval(() => this.pollBatch(), 2000)` (line ~1697). `pollBatch()` updates `this.batchProgress` (drives the modal UI) and, when `status === 'done'`, calls `this.load()` + `this.loadStats()` (line ~1780) to refresh the Pendientes table.

The Pendientes table renders jobs from `this.jobs` (loaded by `load()` → `GET /ia/api-transcriptor`). It does not auto-refresh between `running` polls. Until the batch settles, the user has no signal that workers are creating new `Transcription` rows.

The two surface changes needed:
1. Make `pollBatch()` also drive `load()` while the batch is in-flight.
2. Surface a visual "syncing" indicator on the table toolbar so the auto-refresh is discoverable and not surprising.

## Goals / Non-Goals

**Goals:**
- Throttled `load()` + `loadStats()` during the running phase of the scan batch.
- A small brand-50 pill on the toolbar that toggles with `batchRunning`.
- No regression to the existing done-path (`load()` still fires once at `status === 'done'`).

**Non-Goals:**
- No backend changes.
- No change to `pollBatch()` cadence (still 2s).
- No change to per-row auto-refresh semantics — this only affects the bulk-scan path.

## Decisions

1. **Throttle the table refresh inside `pollBatch()`, not a separate timer.** Reason: avoids a second `setInterval` to manage (and the leaks that come with it), and naturally couples table refreshes to the same polling cycle. Every 2nd poll (≈4s) triggers `this.load()` + `this.loadStats()` when `data.status ∈ {running, starting}`.
2. **`batchTableRefreshTick` counter for the throttle.** Reason: keeps the modulo logic self-contained and reset on `stopBatchPolling()`. Counter is local to the component (Alpine state) — no global state.
3. **Brand-50 pill, not a full-width banner.** Reason: matches the existing bulk action bar styling (also `bg-brand-50 border border-brand-200`) so it feels like a related affordance. Placed inline in the toolbar (next to the manual refresh button) so it doesn't push other controls.
4. **Pill content: `🔄 Sincronizando Pendientes`** — short, descriptive, uses the same icon family (`fa-circle-notch fa-spin`) the rest of the UI uses for in-flight states.

## Risks / Trade-offs

- **Endpoint load**: `GET /ia/api-transcriptor` runs every ~4s while the batch runs. The query is `Transcription::with('file:id,name,storage_provider_id')->orderByDesc('created_at')->limit(200)` — index-backed, fast. Acceptable.
- **User-initiated load collision**: a manual refresh button click during a batch could briefly overlap with the throttled refresh. Both call `this.load()`; the second one resolves later. UI shows `loading=true` via the existing icon spin. No race condition.
- **Counter leak across batches**: `batchTableRefreshTick` could grow unboundedly if `stopBatchPolling()` is missed (e.g., browser tab closed). Reset on `stopBatchPolling()` covers the modal-close path. Reset to 0 on `pollBatch()` `done` branch covers the success path. If the browser tab is killed mid-batch, the counter dies with the component.

## Migration Plan

- Roll-forward: deploy the view changes (no cache bust needed beyond `php artisan view:clear`).
- Rollback: revert the 3 edits in `index.blade.php`; no other code touched.
- No DB, no schema, no environment changes.