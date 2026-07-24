## Context

The API Transcriptor module lives in `app/resources/views/ia/api-transcriptor/index.blade.php` and ships a single Alpine.js component (`apiTranscriptor()`). Three modals live as siblings inside the same `x-data` root:

1. File browser (`x-show="showFiles"`, z-50, `@click.away="showFiles = false"`) — opened from the Storages table.
2. Per-file progress modal (`x-show="showProgress"`, z-50, no `@click.away`).
3. Cross-storage batch modal (`x-show="showBatchModal"`, z-50).

Both bug reports originate from the intersection of (1) and (2): the progress modal sits **on top of** the file browser and is a DOM sibling. Alpine `@click.away` matches by DOM containment, not by visual stacking, so a click on the progress card is treated as "away" from the file-browser card and silently closes `showFiles`.

The single-file dispatch loop is already wired but synchronous from the client's perspective: each `Enviar` button triggers one `POST /transcribe/{fileId}` whose server-side work (ffmpeg → upload → enqueue) takes 30–60 s. Users want bulk selection so they can fire many at once and walk away — exactly what the server can already handle via the existing queue, but the UI gates them behind one-at-a-time clicks.

## Goals / Non-Goals

**Goals:**
- Restrict each modal's click-outside detection to its own backdrop, so closing one never closes another.
- Add multi-select to the file tables inside the file-browser modal (browse / today / yesterday / search) including a select-all header control.
- Add a sticky bulk-action footer showing the selection count and a "Enviar seleccionados" action that fans out parallel `POST /transcribe/{id}` requests.
- Reflect completion in the UI: files flip to `has_transcription = true` as their transcriptions finish (already supported by the existing `markFileTranscribed` path).

**Non-Goals:**
- No changes to backend routes, models, or queue configuration.
- No persistence of selection between modal opens or page reloads.
- No new "priority within a bulk send" controls — concurrency is bounded only by the user's intent and server capacity.
- No changes to the cross-storage "Procesar lote" modal — it remains the tool for batch fan-out across multiple storages by priority.

## Decisions

1. **z-index separation for modal stacking.** Progress modal moves to `z-[60]`; file browser stays at `z-50`. This makes the visual stack unambiguous and is the minimum change that also fixes the click.away bug.
2. **Guard `@click.away` with state checks instead of refactoring DOM hierarchy.**
   - `showFiles` → `@click.away="if (!showProgress && !showBatchModal) showFiles = false"`
   - `showProgress` → `@click.away="if (progressStep === 'done' || progressStep === 'error') closeProgress()"` (so an in-flight run isn't dismissed by accident, but a finished one is)
   Reasoning: lowest-risk surgical fix; no Alpine plugin or wrapper component needed; matches the codebase's flat-modal style.
3. **Selection modeled as `selectedFileIds: new Set()` on the Alpine root.** Using a Set gives O(1) toggle/has checks and a clean conversion to array for the bulk fetch. Reset whenever the file browser closes (`closeFiles()`) so state doesn't leak across storages.
4. **Bulk dispatch via `Promise.allSettled` over N parallel `POST /transcribe/{fileId}` requests.** Each request already goes through the same Laravel controller that runs ffmpeg and enqueues a job; the queue handles the real parallelism server-side. We deliberately don't open N progress modals — instead we close the file browser on submit, show a non-blocking inline status banner inside the modal that lists successes/failures, and the user can re-open the browser any time to see updated `has_transcription` badges.
5. **Select-all is page-scoped, not full-result-scoped.** With up to 2000 rows the select-all header checkbox operates over the currently visible filtered list (`filesFlat` / per-group `files`). A footer chip shows the absolute count.
6. **Per-row checkbox is the only affordance — no shift-select or ctrl-select.** Existing UX uses simple clickable rows; adding keyboard modifiers would clutter the implementation without commensurate payoff at the row counts in play.

## Risks / Trade-offs

- [Browser limits on parallel HTTP/1.1 connections (~6 per origin)] → Mitigation: typical bulk sends are 5–20 files; for larger bursts the browser naturally queues. We don't cap client-side because the server-side queue is the actual concurrency floor and we don't want to lie about dispatch state.
- [User dispatches 100 files and closes the tab] → Mitigation: each individual `POST /transcribe/{id}` is fire-and-forget at the HTTP layer; the backend already persists a `Transcription` row before starting ffmpeg, so aborting the browser does not corrupt data.
- [Progress modal closed prematurely while still running] → Mitigated by gating `@click.away` on terminal states; the explicit X button is still only rendered on `done|error`.
- [Bulk send bypasses the per-file confirm dialog for already-transcribed files] → Mitigation: bulk send **excludes** files where `has_transcription === true` and instead reports them in the summary banner ("3 ya transcritos, omitidos").

## Migration Plan

No data model or schema changes — this is a pure UI patch to a single Blade view. Deploy steps:

1. Replace `index.blade.php` in the next release (no cache bust needed beyond PHP opcache).
2. Smoke-test in `/ia/api-transcriptor`: (a) open file browser, click "Enviar", close progress — file browser must stay open; (b) select 3 files, click "Enviar seleccionados", confirm 3 jobs land in "Trabajos" tab with states progressing from `queued`.

Rollback: revert the view (backend untouched).
