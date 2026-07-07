# jobs-stuck-refresh-bulk Specification

## Purpose
TBD - created by syncing change process-pending-jobs-unified. Update Purpose after archive.
## Requirements
### Requirement: Stuck-job refresh endpoint
The system SHALL expose `POST /ia/api-transcriptor/jobs/{id}/refresh-status` which loads the `Transcription`, calls the existing `syncFromUpstream()` helper to poll the upstream transcriptor API for the row's `job_id`, and returns the updated local state.

#### Scenario: Refresh a job that finished upstream but the webhook was lost
- **WHEN** an admin POSTs to `/jobs/{id}/refresh-status` for a job with `state = 'queued'`, a non-null `job_id`, and the upstream API returns `state = 'done'`
- **THEN** the controller MUST update the local row to `state = 'done'`, download the SRT via the existing `processDone()` path, populate `transcription_segments`, run `KeywordMatcher`, and return 200 with the updated job state
- **AND** the row MUST move to the Completados sub-tab on the next `load()`

#### Scenario: Refresh a job that is still processing upstream
- **WHEN** an admin POSTs to `/jobs/{id}/refresh-status` and the upstream API returns `state = 'processing'`
- **THEN** the controller MUST reflect that local state if not already `processing` and return 200 with `state = 'processing'`
- **AND** the row MUST remain in the Pendientes sub-tab

#### Scenario: Refresh a job that errored upstream
- **WHEN** an admin POSTs to `/jobs/{id}/refresh-status` and the upstream API returns `state ∈ {error, dead}`
- **THEN** the controller MUST mark the local row as `state = error` (or `dead`) with the upstream error message and `finished_at = now()`
- **AND** the row MUST move to the Completados sub-tab (since the sub-tab filter is `state ∈ {done, error, dead}`)

#### Scenario: Refresh on a row with no job_id
- **WHEN** an admin POSTs to `/jobs/{id}/refresh-status` for a row with a null `job_id`
- **THEN** the controller MUST return 422 with an error explaining the row has never been submitted upstream, and MUST NOT make any upstream call

#### Scenario: Upstream unreachable
- **WHEN** an admin POSTs to `/jobs/{id}/refresh-status` and the upstream API call throws
- **THEN** the controller MUST log the error and return 502 with a descriptive message
- **AND** the local row state MUST remain unchanged

### Requirement: Per-row refresh action in the Pendientes sub-tab
The system SHALL surface a per-row button on every job in `state ∈ {queued, processing}` that, when clicked, calls the appropriate endpoint: `POST /jobs/{id}/refresh-status` if the row already has a `job_id`, or the existing `POST /jobs/{id}/dispatch-now` otherwise.

#### Scenario: Per-row button label reflects the action
- **WHEN** the user views the Pendientes sub-tab
- **THEN** every row with `state ∈ {queued, processing}` shows a leading action button
- **AND** the button label is "Refrescar estado" if the row has a `job_id`, otherwise "Enviar ahora"
- **AND** the button `title` tooltip explains which upstream call the click will make

#### Scenario: Refresh action updates state in place
- **WHEN** the user clicks the "Refrescar estado" button on a row
- **THEN** the UI fires `POST /jobs/{id}/refresh-status`
- **AND** on success the Trabajos table reloads
- **AND** if the row moved to `done` it appears in Completados; otherwise it stays in Pendientes with the new state

### Requirement: Bulk refresh fans out to per-row endpoints in parallel
The system SHALL allow a single bulk action in the Pendientes sub-tab action bar to fan out to both `/jobs/{id}/dispatch-now` (for rows without `job_id`) and `/jobs/{id}/refresh-status` (for rows with `job_id`) in parallel, with the same `Promise.allSettled` semantics as the existing bulk dispatch.

#### Scenario: Bulk action routes each row to the correct endpoint
- **WHEN** the user clicks "Procesar N pendientes ahora" with a mix of rows (some with `job_id`, some without)
- **THEN** for each row without `job_id` the engine fires `POST /jobs/{id}/dispatch-now`
- **AND** for each row with `job_id` the engine fires `POST /jobs/{id}/refresh-status`
- **AND** both kinds of requests are issued in parallel in a single `Promise.allSettled`

#### Scenario: Bulk refresh result banner reports refreshed-vs-dispatched counts
- **WHEN** the bulk action settles
- **THEN** the inline banner MUST show four counters: `sentNew` (newly dispatched, no prior `job_id`), `refreshedDone` (refresh returned `done`), `refreshedProcessing` (refresh returned `processing`), and `errors` (non-2xx or exception)
- **AND** rows that became `done` MUST be reflected in Completados after the banner closes and the table reloads