## Context

`app/resources/views/ia/api-transcriptor/index.blade.php` is the single-page UI for the transcriptor module. It already exposes, in the Trabajos tab, a per-row **"Enviar ahora"** button on rows where `state === 'queued' && !job.job_id` (line ~478). Clicking it calls `dispatchJobNow(job)` which fires one synchronous `POST /jobs/{id}/dispatch-now`. The controller (`ApiTranscriptorController::dispatchNow`, line 723) runs the full `ConvertAndTranscribeJob::handle()` inline (ffmpeg + upload + enqueue), so one click = one full pipeline run on one PHP-FPM worker.

The transcriptor batch (`scan_batch`) is 5 files every 2 min by config — there is no parallelism at all in the steady state, even though each pipeline is fully self-contained and the GPU is the actual bottleneck. Users see backlogs grow while the transcriptor sits under-utilized.

The Trabajos sub-tabs (`jobsSubTab: 'pending' | 'completed'`) drive the same `<template x-for="job in jobs">` rendering but the visibility filter splits queued/processing from done/error/dead.

## Goals / Non-Goals

**Goals:**
- Give the user a single-click action to start dispatching all currently queued-sans-job_id rows in parallel.
- Give the user a per-row selection mode for partial fan-outs (e.g. "skip these 3, dispatch the rest").
- Reuse the existing `/dispatch-now` endpoint verbatim — no server changes.
- Surface results in a non-blocking banner so the user can keep navigating.

**Non-Goals:**
- No changes to PHP-FPM worker count, queue config, or scanner cadence.
- No client-side throttling — every selected row fires its own request; the browser/HTTP stack is the only cap.
- No changes to per-row "Enviar ahora", cancel, reprocess, or detail-link affordances.

## Decisions

1. **Action bar above the table, scoped to Pendientes sub-tab.** Mirrors the placement of the file-browser bulk footer above its table; keeps the pattern recognizable across tabs.
2. **Primary button "Procesar todos los pendientes ahora" appears when ≥1 pending row without `job_id` exists.** Disabled when nothing to dispatch or when an in-flight bulk dispatch is running.
3. **Selection mode toggle ("Seleccionar").** When OFF (default), only the primary button is shown. When ON, the primary button changes label to "Procesar N seleccionados ahora" and a leading checkbox column lights up. This keeps the default view uncluttered while exposing the granular mode when needed.
4. **Per-row checkbox enabled only for `state === 'queued' && !job_id`.** Rows that already have `job_id` (or are `processing`) are not redispatchable; we hide/disable the checkbox and add a `title` tooltip explaining why — same idiom used in the file-browser for `has_transcription: true`.
5. **Dispatch engine: `Promise.allSettled` of `POST /jobs/{id}/dispatch-now` with the same headers as `dispatchJobNow`.** Each request is a self-contained PHP process invocation, so true parallelism comes from PHP-FPM forking workers. We capture `{ok, status, job}` per promise, count sent/errors, then call `load()` once to refresh the table.
6. **Result banner lives in the action bar (not a new modal).** Same banner UI as the file-browser bulk footer — three states (success / partial-errors / all-skipped), with an "Aceptar" link that clears the result.
7. **No retroactive refresh polling.** Once the banner shows the settle counts, the user can switch tabs or click refresh. Dispatched jobs will appear in Trabajos with state `queued`/`processing` after `load()` completes; per-row "Enviar ahora" buttons are hidden/disabled once `job_id` is present (already the existing behavior).

## Risks / Trade-offs

- [Concurrent ffmpeg on the PHP-FPM host can spike CPU/RAM] → Mitigation: dispatch is opt-in; user knows the system capacity better than we do, and the worker model already supports this. We do not silently cap.
- [If a `/dispatch-now` request is killed mid-flight, the Transcription row was deleted at the start] → Existing controller behavior; we make it no worse. The bulk banner surfaces errors so the user can retry individually.
- [Browser parallel connection limit (~6 per origin over HTTP/1.1)] → Acceptable: typical burst is 10–30; browser queues the tail transparently. Bulk dispatch already truth-states the result via `Promise.allSettled`, so even browser-queued requests settle into the report.
- [User accidentally clicks "Procesar todos" with 100+ rows] → No confirm dialog; the button is explicit and re-labeled with the count. Power-user behavior — they're asking for it.

## Migration Plan

No data or backend changes. Roll-forward = re-deploy the Blade view. Rollback = revert the view.
