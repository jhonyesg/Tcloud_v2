## Why

The API Transcriptor module (Storages tab → "Ver archivos") has two UX defects that block the most common workflow:

1. **Stacked modals collapse together.** The progress modal that appears after clicking "Enviar" is a sibling of the file-browser modal (both at `z-50`). Alpine's `@click.away` on the file-browser detects any click inside the progress card as "outside" and closes the file browser — leaving the user with an empty page and no way to dispatch more files.
2. **One-at-a-time transcription.** Each "Enviar" button calls the synchronous `POST /transcribe/{fileId}` endpoint, so processing N files requires N sequential round-trips (~30–60s each). Users want to select multiple files and fire them in parallel.

## What Changes

- **Modal isolation.** Give the progress modal a higher z-index than the file browser, gate the file browser's `@click.away` on `!showProgress`, and add an explicit `@click.away` on the progress modal so it can be dismissed independently.
- **Multi-select in file browser.** Add per-row checkboxes (and a select-all header checkbox) on the file tables; expose a sticky action bar at the bottom of the modal showing "N seleccionados" with "Enviar seleccionados" and "Cancelar selección" actions.
- **Bulk parallel dispatch.** New `bulkSendSelected()` method fires one `POST /transcribe/{fileId}` per selected file in parallel (`Promise.all`); the modal closes, files flip to "Hecho" once their job completes, and a brief toast summarizes successes/failures.

## Capabilities

### New Capabilities
- `api-transcriptor-multiselect`: end-to-end multi-file selection and parallel dispatch inside the file browser of the API Transcriptor module

### Modified Capabilities
- (none)

## Impact

- **View**: `app/resources/views/ia/api-transcriptor/index.blade.php` (Alpine state, file table, modal z-stack, new bulk-action bar)
- **Routes / backend**: unchanged — bulk dispatch reuses existing `POST /ia/api-transcriptor/transcribe/{fileId}` (Laravel queue handles parallelism server-side)
- **Specs touched**: none — this is a UI/UX fix inside an existing capability (`transcription-api-orchestrator`) that does not change any external requirement
- **Migrations**: none
- **Risk**: low. Modal isolation is a containment fix; bulk dispatch already runs through the existing Laravel queue per request.

## Non-goals

- No new server-side concurrency control; Laravel queue is unchanged.
- No changes to the existing "Procesar lote" (priority-weighted background batch) modal — that remains for cross-storage bulk scans.
- No persistent selection across navigation — selections live only inside the current file-browser session.
