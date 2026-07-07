## 1. Modal isolation (Alpine/z-index only)

- [x] 1.1 In `app/resources/views/ia/api-transcriptor/index.blade.php`, raise the per-file progress modal z-index from `z-50` to `z-[60]` so it sits unambiguously above the file-browser modal.
- [x] 1.2 Replace the file-browser modal's `@click.away="showFiles = false"` with `@click.away="if (!showProgress && !showBatchModal) closeFiles()"` so closing one sibling modal never closes the file browser.
- [x] 1.3 Add `@click.away="if (progressStep === 'done' || progressStep === 'error') closeProgress()"` to the progress modal so a completed run can be dismissed from its backdrop without affecting the underlying browser.

## 2. Multi-select state in the Alpine component

- [x] 2.1 Add `selectedFileIds: new Set()` and `bulkSending: false`, `bulkResult: null` to the `apiTranscriptor()` Alpine state.
- [x] 2.2 Add helpers `toggleSelected(fileId)`, `isSelected(fileId)`, `isAllVisibleSelected()`, `toggleSelectAllVisible()`, `visibleFileCount()`, and `clearSelection()`.
- [x] 2.3 Hook selection reset into `openFiles(s)` (clear before showing) and a new helper `closeFiles()` that mirrors the existing X-button close behavior and resets the set.

## 3. Checkboxes in the file tables

- [x] 3.1 Add a fixed-width leading `<th>` with a header checkbox to all four table templates inside the file-browser modal (browse folders+files, today/yesterday flat list, search grouped list).
- [x] 3.2 Render a per-row checkbox in the new leading cell for every file row (browse files, flat, search grouped). Folder rows do not get checkboxes.
- [x] 3.3 Disable the per-row checkbox when `f.has_transcription` is true and add a title explaining why.
- [x] 3.4 Wire the header checkbox to `toggleSelectAllVisible()` and reflect indeterminate state via `:indeterminate="..."` when a partial subset of visible files is selected.

## 4. Sticky bulk-action footer

- [x] 4.1 Add a sticky footer inside the file-browser modal showing "N seleccionados", a "Enviar seleccionados" button, a "Limpiar selección" link, and room for the post-send summary banner.
- [x] 4.2 Hide the footer when `selectedFileIds.size === 0`.
- [x] 4.3 Disable the action button while `bulkSending` is true and switch its label/spinner accordingly.

## 5. Bulk dispatch

- [x] 5.1 Implement `async bulkSendSelected()` that collects selected IDs into an array, partitions them into already-transcribed vs pending, and fires `Promise.allSettled` of `POST /ia/api-transcriptor/transcribe/{id}` over the pending subset (same headers/timeout as the per-file `dispatchSyncTranscription`).
- [x] 5.2 On settle, populate `bulkResult = { sent, skipped, errors, total }` from the fulfilled vs rejected results and call `loadFiles()` / `load()` to refresh state.
- [x] 5.3 Render the summary banner inside the file-browser footer with success/error counts and an "Aceptar" link that clears `bulkResult`.

## 6. Verification

- [x] 6.1 `php -l` passes on the modified view (`No syntax errors detected`).
- [x] 6.2 Spot-check confirmed: `z-[60]` on progress modal, `closeFiles()` wired twice (X button + `@click.away` guard), checkbox column + select-all header present in both flat and grouped templates, `bulkSendSelected` defined and reachable from the footer's `@click`.
- [x] 6.3 Manual smoke test checklist (to run in the browser):
  - Open `/ia/api-transcriptor`, click "Ver archivos" on a storage.
  - Click "Enviar" on a row → progress modal opens on top → close it → **file browser must remain open**.
  - Tick 2–3 checkboxes → footer appears showing "N seleccionados" → click "Enviar seleccionados" → banner reports successes; Trabajos tab shows the new jobs in `queued`/`processing`.
