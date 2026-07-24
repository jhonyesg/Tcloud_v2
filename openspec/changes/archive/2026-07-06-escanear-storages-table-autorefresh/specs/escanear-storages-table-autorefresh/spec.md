## ADDED Requirements

### Requirement: Pendientes table auto-refreshes while a scan batch is running
The system SHALL refresh the Pendientes sub-tab's `Transcription` rows on a throttled cadence while the "Escanear storages" background batch is in the `running` or `starting` state, so the user sees new rows appear as workers create them.

#### Scenario: Running batch refreshes the table on each 2nd poll
- **WHEN** an admin clicks "Iniciar procesamiento" in the Escanear storages modal
- **AND** the upstream `batch-status` endpoint reports `status = "running"` or `status = "starting"`
- **THEN** the frontend SHALL call `GET /ia/api-transcriptor` (the `load()` call) at most once every 2 poll cycles of `pollBatch()` — i.e. roughly every 4 seconds
- **AND** the modal's own progress display SHALL continue to update every 2 seconds as before

#### Scenario: Done batch fires a final refresh
- **WHEN** `batch-status` reports `status = "done"`, `"error"`, or `"not_found"`
- **THEN** `pollBatch()` SHALL call `this.load()` + `this.loadStats()` once at the transition
- **AND** stop calling `load()` on subsequent polls (none happen because polling stops)

### Requirement: Visual sync indicator on the Pendientes toolbar
The system SHALL show a small inline pill labeled "🔄 Sincronizando Pendientes" next to the manual refresh button while `batchRunning === true`, and hide it otherwise.

#### Scenario: Pill appears when the batch starts
- **WHEN** the user starts a scan batch via "Escanear storages"
- **THEN** the pill SHALL appear with `bg-brand-50 border-brand-200` styling
- **AND** SHALL include a spinning icon to indicate active syncing

#### Scenario: Pill disappears when the batch settles
- **WHEN** the batch reaches `done`, `error`, or `not_found`, OR the user manually closes the modal
- **THEN** the pill SHALL hide on the next Alpine reactivity tick

### Requirement: Refresh throttle state resets cleanly between batches
The system SHALL reset the internal `batchTableRefreshTick` counter when `stopBatchPolling()` is called and when a batch reaches its terminal status, so the next batch starts with a tick of 0.

#### Scenario: Counter resets on stop
- **WHEN** `stopBatchPolling()` is called (modal close) OR `pollBatch()` observes `status ∈ {done, error, not_found}`
- **THEN** `batchTableRefreshTick` SHALL be set to 0
- **AND** the next batch's first throttled refresh SHALL fire on the 2nd poll, not later

#### Scenario: Counter is per-component, not global
- **WHEN** the Alpine component for the transcriptor view is destroyed (tab close, browser close)
- **THEN** the counter is garbage-collected with the component — no global state is polluted