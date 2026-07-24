## ADDED Requirements

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

### Requirement: Bulk dispatch reports a per-row result
The system SHALL report the count of dispatched, errored, and skipped jobs after a bulk dispatch finishes, without blocking the user.

#### Scenario: Settled banner shows counts
- **WHEN** all dispatched `POST /jobs/{id}/dispatch-now` requests have settled
- **THEN** an inline banner inside the action bar shows:
  - the number dispatched successfully
  - the number that returned a non-2xx response
  - the number already submitted (server reports `already_submitted: true`) or otherwise skipped

#### Scenario: Banner has an accept action that closes it
- **WHEN** the result banner is displayed
- **AND** the user clicks "Aceptar"
- **THEN** the banner clears
- **AND** no jobs are re-dispatched

### Requirement: Bulk dispatch does not interfere with existing per-row actions
The system SHALL keep the existing per-row "Enviar ahora", "Cancelar", "Reprocesar", and detail-link affordances working alongside the new bulk action.

#### Scenario: Per-row actions remain available
- **WHEN** the bulk action bar is visible
- **THEN** each row still exposes its existing per-row action buttons
- **AND** the per-row "Enviar ahora" button is the visible/clickable affordance only for rows that are dispatchable; bulk action is an alternative, not a replacement

#### Scenario: Dispatched rows refresh in place
- **WHEN** a bulk dispatch finishes
- **THEN** the Trabajos table is reloaded
- **AND** rows that were successfully dispatched now show state `queued` or `processing` with their `job_id`
- **AND** the bulk button count drops accordingly
