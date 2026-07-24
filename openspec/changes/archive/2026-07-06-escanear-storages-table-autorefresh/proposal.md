## Why

When the admin clicks "Escanear storages" (the top-right button that triggers the background `transcription:process-batch` console command), the modal shows live progress but the Pendientes sub-tab table stays frozen: new `Transcription` rows only appear when the batch finishes and `pollBatch()` calls `this.load()` one final time. With batches of 50+ files this leaves the user staring at a table that doesn't update for ~2 minutes and no visual cue that something is happening in the background.

## What Changes

- **Auto-refresh the Pendientes table while the batch is running.** Inside `pollBatch()`, when the upstream batch status is `running` or `starting`, throttle a `this.load()` + `this.loadStats()` call to every 2nd poll (~4s). Modal progress still updates every 2s.
- **Visual indicator on the table toolbar.** A small inline pill "🔄 Sincronizando Pendientes" appears next to the manual refresh button while `batchRunning === true` and disappears when the batch settles. Uses the same brand-50 background as the bulk action bar for visual coherence.
- **Cleanup on modal close.** `stopBatchPolling()` also resets `batchTableRefreshTick` so the next batch starts at tick 0 and the throttling is consistent.

## Capabilities

### New Capabilities

- `escanear-storages-table-autorefresh`: the Pendientes sub-tab auto-refreshes its `Transcription` rows on a throttled interval while the "Escanear storages" background batch is running, with a visible sync indicator.

### Modified Capabilities

- (none — existing `transcription-api-orchestrator` requirements are unaffected; this is a UI affordance, not an orchestrator behavior change)

## Impact

- **View only**: `app/resources/views/ia/api-transcriptor/index.blade.php` (3 edits: `pollBatch()`, the toolbar markup, `stopBatchPolling()`).
- **Backend / routes / models / migrations**: none.
- **Existing behavior preserved**: the batch command, modal, polling, and final `load()` call are unchanged. Only an extra `load()` happens on the throttled cadence.

## Non-goals

- Not pre-creating `Transcription` rows in `ProcessBatchCommand` before dispatching the queue jobs — that would change backend semantics and was explicitly deferred.
- Not changing the polling cadence of the modal itself (still 2s).
- Not adding auto-refresh for unrelated workflows (manual "Enviar ahora", "Reprocesar", "Refrescar estado" — those already trigger their own `load()`).
- Not changing `ScanStaleJobsCommand` cron cadence.