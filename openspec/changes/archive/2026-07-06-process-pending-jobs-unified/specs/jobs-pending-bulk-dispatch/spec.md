## MODIFIED Requirements

### Requirement: Bulk "Procesar ahora" action in the Pendientes sub-tab
The system SHALL expose a bulk dispatch action in the Trabajos → Pendientes view that fans out per-row requests in parallel: `POST /jobs/{id}/dispatch-now` for rows without a `job_id`, and `POST /jobs/{id}/refresh-status` for rows that already have a `job_id` (see capability `jobs-stuck-refresh-bulk`).

#### Scenario: Default view shows the all-pending button
- **WHEN** the user is on the Trabajos tab with the Pendientes sub-tab active
- **AND** at least one job row has `state ∈ {queued, processing}` (regardless of `job_id`)
- **THEN** a primary action button is visible reading "Procesar N pendientes ahora" (N = count of rows in those states)
- **AND** clicking it fires one request per such row in parallel, choosing `/dispatch-now` when `job_id` is null and `/refresh-status` when `job_id` is set

#### Scenario: All-pending button hidden when nothing to dispatch
- **WHEN** no row in the current view is in state `queued` or `processing`
- **THEN** the bulk action button is hidden or disabled with a non-blocking hint

#### Scenario: Selection mode reveals per-row checkboxes
- **WHEN** the user toggles the "Seleccionar" switch on in the Pendientes action bar
- **THEN** every dispatchable row (`state ∈ {queued, processing}`) shows a leading checkbox
- **AND** the primary button's label changes to "Procesar N seleccionados ahora"
- **AND** checking the header checkbox selects every currently visible dispatchable row

#### Scenario: Per-row checkbox is disabled for non-dispatchable rows
- **WHEN** a row's state is `done`, `error`, or `dead`
- **THEN** its checkbox is disabled and shows a tooltip explaining why
- **AND** it is excluded from any "select all" or bulk count

## REMOVED Requirements

### Requirement: Per-row checkbox is disabled for non-dispatchable rows when `job_id` is set
**Reason**: The prior requirement disabled the checkbox for rows with a `job_id`, under the assumption that such rows were already being processed upstream. In production, jobs frequently remain in `state = queued` after submission because the webhook callback was lost. Disabling those rows made the bulk action useless against the most common production scenario.
**Migration**: The behavior is replaced by the `MODIFIED` requirement above, which considers every row in `state ∈ {queued, processing}` dispatchable, and the per-row / bulk action routes to `/refresh-status` for rows with `job_id` (see `jobs-stuck-refresh-bulk`).