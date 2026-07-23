## Why

El botón "Procesar N pendientes ahora" del módulo API Transcriptor dispara N POSTs HTTP simultáneos hacia `/ia/api-transcriptor/jobs/{id}/dispatch-now`. Cada POST arranca un proceso PHP-FPM, abre conexión PostgreSQL, corre `ffmpeg` (30-60s) y mantiene la conexión abierta todo ese tiempo. Con N=1356 (cantidad de filas pending actuales), el resultado es `SQLSTATE: FATAL: sorry, too many clients already` — 234 errores así en 10 días según `laravel.log`.

La infraestructura ya existe: `ConvertAndTranscribeJob::dispatchWithPriority()` enqueue a Redis, y `/etc/supervisor/conf.d/tcloud-transcription-worker.conf` corre 10 workers en `queue=transcription-high,medium,low`. Solo falta exponer este pipeline al click del admin.

## What Changes

- **Nuevo endpoint liviano** `POST /ia/api-transcriptor/jobs/bulk-dispatch` que recibe `{ ids: [...] }` (o vacío para auto-seleccionar todos los dispatchable del usuario) y encola cada `Transcription::id` como `ConvertAndTranscribeJob` en Redis, calculando prioridad por storage. Responde en <500ms con `{ enqueued: N, skipped_queued: M, errors: K }`.
- **Refactor del frontend** `bulkDispatchPending()` (`app/resources/views/ia/api-transcriptor/index.blade.php:1659`) para hacer UN solo `fetch` al nuevo endpoint, no N+ promises paralelas a `dispatch-now`. La UI muestra progreso en vivo vía polling de `stats` (los conteos `queued`/`processing` van bajando conforme los workers procesan).
- **Idempotencia y validación**: el endpoint rechaza ids que no sean `state IN {pending|queued|processing}` con `job_id IS NULL`, devuelve `skipped_queued` para ellos. Si la cola Redis no está accesible, devuelve 503 explícito (no 500 ambiguo).
- **Rate limit**: tope de 2000 encolados por request (protección admin contra accidente) — coherente con `limit(200)` ya usado en `indexData()`.

## Capabilities

### New Capabilities
- `transcriptor-bulk-redis-dispatch`: define cómo el frontend del módulo API Transcriptor encola N transcripciones a Redis usando un único endpoint HTTP, en lugar de N dispatchs síncronos.

### Modified Capabilities
- (ninguno — modifica comportamiento de UI pero no hay spec existente que cubra `bulkDispatchPending`)

## Impact

- **Backend**: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — nuevo método `bulkDispatch(Request $request)` + ruta en `app/routes/web.php:167`.
- **Job**: `app/app/Jobs/ConvertAndTranscribeJob.php` — sin cambios funcionales (solo se invoca `dispatchWithPriority()` igual que ya hace `ScanAndSubmitCommand:75`).
- **Infraestructura**: cero cambios. Worker supervisord ya está configurado.
- **Frontend**: `app/resources/views/ia/api-transcriptor/index.blade.php` línea 1659 (`bulkDispatchPending()`) — reemplazar `Promise.allSettled(targets.map(fetch))` por un único POST.
- **No requiere migración**.

## Non-goals

- NO se modifica `ConvertAndTranscribeJob`, `TranscriptionSubmitService`, ni el worker supervisord — solo se invoca lo que ya existe.
- NO se arregla el scheduler `scan-and-submit` (eso es otro change pendiente). Este change expone el camino Redis al admin; el otro arregla por qué `scan-and-submit` no se está ejecutando automáticamente.
- NO se cambia la API externa del transcriptor.
- NO se eliminan `dispatch-now` ni `refresh-status` individuales — siguen disponibles para uso uno-a-uno (ej. modal de progreso "Enviar ahora" de un único archivo).
