## Why

The API Transcriptor module currently feeds work to the GPU at the scanner's pace (configurable default `scan_batch = 5` files per 2-min tick — observed in production as "five at a time"). When many recordings pile up, the GPU sits idle for hours while the queue drains slowly even though each job, once started, fully utilizes the transcriptor and could run concurrently.

The Trabajos tab already exposes a per-row "Enviar ahora" button that hits `POST /jobs/{id}/dispatch-now`. Workers are forced through them serially by clicking one at a time, even though the endpoint is a self-contained ffmpeg + upload + enqueue cycle that the Laravel queue can already parallelize — we just need a parallel fan-out at the UI layer.

## What Changes

- **Bulk "Procesar ahora" action in Trabajos → Pendientes.** A top action bar surfaces a primary "Procesar N pendientes ahora" button (N = count of pending rows without `job_id`) and a secondary "Seleccionar" toggle that reveals per-row checkboxes.
- **Two dispatch modes, one engine.** Either mode calls the same `bulkDispatchPending(ids)` method that fans out one `POST /jobs/{id}/dispatch-now` per selected job in parallel (`Promise.allSettled`) — each request runs the full ffmpeg → upload → enqueue cycle on a separate PHP-FPM worker so the GPU sees concurrent uploads.
- **Live result summary.** A non-blocking toast/banner (reusing the visual idiom of the file-browser bulk footer) reports sent / errors / skipped counts; the Trabajos table refreshes on settle.
- **No new backend routes, no new jobs.** This is a UI fan-out over the existing `/dispatch-now` endpoint. Server picks up the parallelism for free.

## Capabilities

### New Capabilities
- `jobs-pending-bulk-dispatch`: bulk parallel dispatch of pending transcriptions from the Trabajos tab

### Modified Capabilities
- (none)

## Impact

- **View**: `app/resources/views/ia/api-transcriptor/index.blade.php` (Pendientes table gets checkbox column; new action bar above the table; new `bulkDispatchPending` Alpine method)
- **Routes / backend**: unchanged — reuses existing `POST /ia/api-transcriptor/jobs/{id}/dispatch-now`
- **Server capacity**: linear with selection size. Browser typically allows ~6 parallel HTTP/1.1 requests per origin; larger bursts queue. No client-side cap to keep dispatch state truthful.
- **Migrations**: none

## Non-goals

- No client-side concurrency cap or exponential backoff (server is the source of truth; user sees what really got dispatched).
- No changes to the scanner cadence (`scan_batch`), to `process-batch`, or to per-row "Enviar ahora" — those stay as power-user affordances.
- No new cross-storage logic — this only affects the Trabajos tab.
