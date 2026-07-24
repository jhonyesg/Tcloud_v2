## ADDED Requirements

### Requirement: File-browser modal is isolated from sibling modals
The system SHALL keep the file-browser modal open when the user opens, interacts with, or closes the per-file progress modal that overlays it.

#### Scenario: Sending a file from the file browser keeps the browser open
- **WHEN** the user clicks "Enviar" on a file row inside the file-browser modal
- **THEN** the per-file progress modal appears on top of the file browser
- **AND** the file-browser modal remains visible behind the progress modal
- **AND** closing the progress modal does not close the file-browser modal

#### Scenario: Clicks inside the progress modal do not close the file browser
- **WHEN** the progress modal is displayed over the file browser
- **AND** the user clicks anywhere inside the progress modal card (including its action buttons)
- **THEN** only the progress modal's own state changes
- **AND** the file browser remains open

#### Scenario: Clicks on the progress modal backdrop do not close the file browser
- **WHEN** the progress modal is displayed over the file browser
- **AND** the user clicks on the progress modal's dimmed backdrop area
- **THEN** the file browser remains open

### Requirement: Progress modal is dismissible on its own
The system SHALL let the user close the per-file progress modal via its backdrop click once the transcription reaches a terminal state.

#### Scenario: Backdrop dismisses only completed progress
- **WHEN** the per-file progress modal is open
- **AND** the transcription has finished with state `done`, `error`, or `dead`
- **AND** the user clicks the modal's backdrop area
- **THEN** the progress modal closes
- **AND** the underlying file browser (if open) is unaffected

#### Scenario: Backdrop is inert while transcription is in flight
- **WHEN** the per-file progress modal is open
- **AND** the transcription is in `converting`, `uploading`, `queued`, or `processing`
- **AND** the user clicks the modal's backdrop area
- **THEN** the progress modal stays open until the run completes

### Requirement: User can multi-select files in the file browser
The system SHALL let the user select zero or more files from any of the file-browser tables (browse / today / yesterday / search) using per-row checkboxes and a select-all control.

#### Scenario: Each row exposes a checkbox
- **WHEN** the file-browser modal is showing the files table
- **THEN** every file row renders a checkbox bound to that file's selection state
- **AND** the checkbox state toggles independently of any other UI on the row

#### Scenario: Header checkbox selects every currently visible file
- **WHEN** the file-browser modal is showing the files table
- **AND** the user toggles the header "select all" checkbox to checked
- **THEN** every file currently visible in the filtered list becomes selected
- **AND** toggling it to unchecked clears the selection

#### Scenario: Selection persists across filter changes within the same modal session
- **WHEN** the user has selected files in the file browser
- **AND** the user switches mode (browse → today → search → etc.) or applies a column filter
- **THEN** the selection of files that remain visible is preserved

#### Scenario: Closing the file browser clears the selection
- **WHEN** the user closes the file-browser modal (via X button, backdrop, or navigating away)
- **THEN** the selection set is cleared
- **AND** reopening the modal starts with an empty selection

### Requirement: Bulk selection dispatch runs in parallel
The system SHALL dispatch one transcription job per selected file in parallel and report the outcome without blocking the user.

#### Scenario: Sending N selected files fans out N requests
- **WHEN** the user has N ≥ 1 pending files selected in the file browser
- **AND** the user clicks "Enviar seleccionados"
- **THEN** the system fires one `POST /transcribe/{fileId}` request per selected file, in parallel
- **AND** files already marked `has_transcription` are skipped and reported as such
- **AND** an inline banner inside the file browser summarizes successes and failures once all requests settle

#### Scenario: Bulk dispatch does not open per-file progress modals
- **WHEN** the user triggers bulk dispatch from the file browser
- **THEN** no per-file progress modal opens
- **AND** the user can continue using the page (switching to the Trabajos tab, opening a different storage's files, etc.)

#### Scenario: Dispatched files surface in the Trabajos tab
- **WHEN** the bulk dispatch completes for at least one file
- **AND** the user switches to the Trabajos tab
- **THEN** a new job appears for that file with state `queued` or `processing`
- **AND** the corresponding row in the file browser reflects `has_transcription` once the transcription finishes
