# jobs-stuck-refresh-bulk Specification

## Purpose
TBD - created by syncing change process-pending-jobs-unified. Update Purpose after archive.
## Requirements
### Requirement: Stuck-job refresh endpoint

El sistema SHALL exponer `POST /ia/api-transcriptor/jobs/{id}/refresh-status`, que carga la
`Transcription`, llama al helper `syncFromUpstream()` para consultar la API externa por el
`job_id` de la fila, y devuelve el estado local actualizado.

El destino de la fila tras el refresco SHALL respetar la separación en tres sub-tabs: las
que pasan a `done` van a **Completados**, y las que pasan a `error` o `dead` van a
**Fallidos** (antes ambas iban a Completados).

#### Scenario: Refrescar un job que terminó upstream pero perdió el webhook
- **WHEN** un admin hace POST sobre un job en `queued` con `job_id` no nulo y la API externa
  responde `state = 'done'`
- **THEN** el controlador actualiza la fila a `done`, descarga el SRT por la vía
  `processDone()`, puebla `transcription_segments`, ejecuta `KeywordMatcher` y devuelve 200
- **AND** la fila pasa a la sub-tab **Completados** en el siguiente `load()`

#### Scenario: Refrescar un job que sigue procesándose upstream
- **WHEN** la API externa responde `state = 'processing'`
- **THEN** el controlador refleja ese estado local y devuelve 200
- **AND** la fila permanece en la sub-tab Pendientes

#### Scenario: Refrescar un job que falló upstream
- **WHEN** la API externa responde `state ∈ {error, dead}`
- **THEN** el controlador marca la fila con ese estado, el mensaje de error remoto y
  `finished_at = now()`
- **AND** la fila pasa a la sub-tab **Fallidos**

#### Scenario: Refrescar una fila sin job_id
- **WHEN** un admin hace POST sobre una fila con `job_id` nulo
- **THEN** el controlador devuelve 422 explicando que la fila nunca se envió upstream, y no
  hace ninguna llamada externa

#### Scenario: API externa inalcanzable
- **WHEN** la llamada a la API externa lanza excepción
- **THEN** el controlador loguea el error y devuelve 502 con un mensaje descriptivo
- **AND** el estado local no cambia

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