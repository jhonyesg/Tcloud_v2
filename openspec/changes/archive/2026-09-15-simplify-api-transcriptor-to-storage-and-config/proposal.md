## Why

El módulo `/ia/api-transcriptor` arrastra 5056 líneas de vista y 2545 de controller con cuatro pestañas (Storages, Trabajos, Consumo, Configuración), varias de ellas **inútiles hoy**: el upstream de transcripción no está enviando trabajos (delay promedio de despacho = **3.7 horas**, 8,284 filas en `pending`, jobs `queued` colgados desde 2026-09-14 21:53). El operador entra, ve "no hay nada" en Trabajos/Consumo, y no puede actuar porque ningún botón arregla el upstream. La superficie de UI ahoga al módulo en sus dos únicas funciones útiles: **elegir qué storages se transcriben** y **ajustar el regulador**.

## What Changes

**Eliminar completo** lo siguiente del módulo:

- Pestaña **Trabajos**: listados paginados, sub-tabs (pending/completed/failed/all), bulk dispatch, dispatch-now, retry, cancel, reprocess, refresh-status, delete-upstream, unstick, retry-batch
- Pestaña **Consumo**: cards de GPU/VRAM/ramdisk, sparkline de 60 min, tabla de jobs más antiguos, decisión del regulador
- Botón **"Escanear storages"** + modal completo + barra inline de progreso (`openBatchModal`, `processBatch`, `estimateScan`, `batchStatus`)
- Botones **"Salud API"** y **"Guía"** (tour interactivo del módulo)
- Página `/ia/api-transcriptor/jobs/{id}` y vista `job-detail.blade.php`
- Partial `_pipeline-diagnostics.blade.php` (latencia p50/p95 + semáforo del regulador)
- Banner de "carpetas sin archivos" dentro del tab Storages
- Cards de "Trabajos N" sobre el tab Storages (origen: `/stats`)
- Navegación de archivos por storage (`storageFiles`, `process-folder`, `process-day`)
- Transcripción manual de un archivo (`transcribeFile`)

**Conservar**:

- Tab **Storages** (lista + toggle por storage + vista de scope heredado) — simplificado
- Tab **Configuración** completo (`transcriptor.dispatch_paused`, tick, workers, regulador, settings)
- Endpoint `POST /ia/api-transcriptor/storages/{id}/toggle` (única escritura)
- Endpoints de settings (`GET/POST/POST reset/POST run-tick`)
- Toda la pipeline backend: tick automático, scan-and-submit, polling, regulator, workers Redis. **Esto NO se toca**, sigue siendo la fuente de verdad del discovery y despacho.

### Contrato con módulos dependientes (Correcciones y Avisos Inteligentes)

El módulo API Transcriptor es el **proveedor** de la materia prima que consumen los módulos de Correcciones y Avisos Inteligentes. El siguiente contrato se preserva íntegro al aplicar este cambio — la pérdida de cualquier ítem bloquea el flujo de los dos módulos consumidores y NO es aceptable:

**Modelos Eloquent (NO se tocan)**:

- `App\Models\Transcription` — estado, `srt_content`, `duration_seconds`, `corrected`, `word_count`, `started_at`, `finished_at`, `error_message`, `retries`
- `App\Models\TranscriptionSegment` — filas de segmentos con `text`, `start_seconds`, `end_seconds`, `source_segment_id` (hidratación de coherencia)
- `App\Models\TranscriptionReview` — estado de revisión humana de una transcripción

**Servicios backend (NO se tocan)**:

- `TranscriptionProcessor` — punto de entrada que `TranscriptionPollingService` llama cuando un job llega a `done` desde upstream. Crea `TranscriptionSegment`, dispara `KeywordMatcher`, `TranscriptionCoherencePass::run()` y persiste `state=done`. **Si esto se rompe, ni Correcciones ni Avisos ven contenido nuevo.**
- `TranscriptionPollingService` — corre cada minuto vía `transcription:poll-results`; puente único entre upstream y BD local.
- `TranscriptionSubmitService` — envía jobs al upstream; llamado por `TranscriptionTickCommand` (Phase 2) y por `ConvertAndTranscribeJob`.
- `TranscriptionCoherencePass` — pase de coherencia post-transcripción que hidrata `source_segment_id` desde el diccionario approved. Consumido por `TranscriptionProcessor` durante `processDone()`.
- `TranscriptionReviewService` — backend del visor de revisión (Correcciones). NO se toca.
- `TranscriptorApiClient` — cliente HTTP al upstream. Métodos `getRemoteInfo()`, `getRemoteStats()`, `cancelUpstream()`, `deleteUpstream()`, `unstickUpstream()`, `getHealth()` se siguen usando desde el backend incluso si los endpoints UI desaparecen.
- `TranscriptorSettings` — schema y reader de settings (sigue vivo, lo usa `TranscriptionTickCommand` y `TranscriptorSettingsController`).

**Cron jobs (NO se tocan)**:

- `transcription:tick` (`*/2 * * * *`) — discovery + dispatch
- `transcription:poll-results` (`* * * * *`) — puente de retorno
- `transcription:scan-and-submit` (CLI directo) — barridos manuales
- `transcription:tune` (`*/5 * * * *`) — autoajuste de workers systemd
- `transcription:consumption-snapshot` (`* * * * *`) — **se elimina** (solo alimenta el panel Consumo eliminado)
- `transcription:health-check` (`0 * * * *`) — alerta si upstream no responde
- `transcription:check-shm-health` (`*/10 * * * *`) — `/dev/shm` watchdog
- `transcription:retry-batch-upstream` (`0 4 * * 1`) — reintento masivo semanal
- `transcription:backfill-corrected-audit` (`0 3 * * 2`) — auditoría
- `transcription:cleanup-tmpfs` (`0 * * * *`) — limpieza tmp
- `transcription:cleanup-orphan-wav` (`*/15 * * * *`) — wav orphans

**Tablas (NO se tocan)**:

- `transcriptions` — 8,284 `pending` + 1,904 `queued` históricos quedan intactos; `transcription:tick` los sigue procesando cuando el upstream vuelva.
- `transcription_segments` — hidrata Correcciones.
- `transcription_reviews` — hidrata Correcciones.
- `transcription_enabled` en `storage_providers` — único escritor sigue siendo `toggleStorage()` (sin cambio).

**Endpoints que NO se eliminan aunque sean "del módulo"** (los consumen otros modelos vía sus servicios):

- `POST /ia/api-transcriptor/storages/{id}/toggle` — única escritura. Si se quita, `transcription_enabled` queda huérfano y los módulos consumidores dejan de poder prender canales nuevos. **Protegido.**

**Lo que SÍ consumen Correcciones y Avisos (extracto del grep 2026-09-15)**:

| Consumidor | Lo que lee | Origen preservado |
|---|---|---|
| `CorreccionesController::transcriptionReviewList` | `Transcription::where('state', STATE_DONE)` | Modelo + tabla |
| `CorreccionesController::transcriptionReviewDetail` | `Transcription::findOrFail` + `TranscriptionSegment` | Modelo + tabla |
| `CorrectionService::appliesToTranscription` | `Transcription` instance + segmentos | Modelo |
| `CorrectionService::chunkById` (reaplicar dictionary) | `TranscriptionSegment::query()` | Tabla + modelo |
| `TranscriptionProcessor::processDone` | crea segmentos, llama `KeywordMatcher`, `TranscriptionCoherencePass` | Servicio |
| `TranscriptionCoherencePass::run` | `Transcription` + `TranscriptionSegment`, escribe `source_segment_id` | Servicio + tabla |
| `TranscriptionReviewService::list/detail/updateReview` | `Transcription` + `TranscriptionReview` | Servicio + modelos |
| `AvisosScanService::scanPair` | `Transcription::findOrFail` + `KeywordMatcher::run` | Modelo + servicio |
| `AvisosScanService::selectCandidates` | `transcriptions as t` LEFT JOIN `transcription_segments` | Tabla |
| `MentionsSearchService::visibleTranscription` | `Transcription::STATE_DONE` + `transcription_segments` + `user_storages.transcription_access` | Modelo + tabla |
| `MentionBackfillService` | `Transcription::STATE_DONE` + `TranscriptionSegment` | Modelo + tabla |

Ningún consumidor llama ningún endpoint del controller de API Transcriptor (los hits de grep contra `ApiTranscriptorController::` son todos: definición de rutas, código del propio controller, 2 test harnesses, comentarios históricos en migraciones y modelo). **Eliminar los 24 endpoints UI no rompe nada del contrato cross-module.**

## Capabilities

### New Capabilities

_Ninguna. Es una simplificación de UI, no introduce comportamiento nuevo._

### Modified Capabilities

- `transcription-api-orchestrator`: eliminar los requirements "Admin puede ver transcriptions recientes y sus detalles", "Admin puede re-encolar un job fallido manualmente", "Procesamiento por lote en background con alertas opcionales", "Procesamiento manual por carpeta o día", "Re-encolar upstream de jobs zombies vía `unstick`", "Eliminar upstream + local con `delete_upstream`", "Reintento masivo con `retry-batch`", "Cancelación upstream centralizada en cliente" — y el requirement "Scanner detecta archivos nuevos" se mantiene tal cual (es backend).
- `transcription-orchestrator-runtime`: en la sección "13. Superficie de observación y control" eliminar las bullets de `/ia/api-transcriptor/latency` y `/ia/api-transcriptor/regulator-cause`; en la sección "14. Persistencia de la decisión del regulador en cache" eliminar el cache `transcriptor:tick:last_decision` (ya no se consume desde UI) y los scenarios del endpoint `/regulator-cause`.
- `transcriptor-jobs-listing`: retirar spec entera (era 100% del tab Trabajos).
- `transcriptor-state-visibility`: retirar spec entera (cards de "Trabajos N").
- `transcriptor-empty-folders-per-storage-cache`: retirar spec entera y la cache `transcriptor:empty_folders:*`.
- `transcriptor-stats-health-cache`: retirar spec entera y las caches `transcriptor:stats:combined` y `transcriptor:health:combined`.
- `transcriptor-bulk-redis-dispatch`: retirar spec entera (endpoint bulk-dispatch desaparece).
- `transcriptor-pipeline-latency`: retirar spec entera (partial diagnostics desaparece).
- `transcriptor-regulator-signals`: limpiar referencias a UI de "regulador", mantener el contrato del regulador como servicio backend.
- `transcriptor-storage-files-srt-link`: retirar spec entera (modal de archivos por storage desaparece).
- `transcriptor-scan-scope`: retirar spec entera (modal de scan desaparece — el scan auto del tick sigue activo).
- `transcriptor-index-load`: limpiar el bloque `ui_limits` del payload de `indexData()` (el campo deja de emitirse al cliente).

## Impact

**Código a eliminar / reducir**:

- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`: 2545 → ~400 líneas. Métodos que se van: `show`, `retry`, `bulkDispatch`, `dispatchNow`, `refreshStatus`, `reprocess`, `cancelJob`, `destroy`, `storageFiles`, `scanStorage`, `processFolder`, `processDay`, `estimateScan`, `processBatch`, `batchStatus`, `transcribeFile`, `jobStatus`, `transcript`, `transcribeProgress`, `syncStorage`, `latency`, `regulatorCause`, `liveConsumption`, `emptyFolders`, `stats`, `health`, `shmStatus`, `pausedResponse`. Constantes `JOBS_PER_PAGE_*`, `JOBS_WINDOW_MAX`, `JOB_SCOPES` también se van.
- `app/resources/views/ia/api-transcriptor/index.blade.php`: 5056 → ~600 líneas. Se eliminan: cabecera de tabs Trabajos/Consumo, banner empty-folders, cards Trabajos N, partial `_pipeline-diagnostics` include, tab Trabajos completo, tab Consumo completo, botón "Escanear storages", botón "Salud API", botón "Guía", función JS `startApiTranscriptorTour()` y su `apiTranscriptorTour` data, Alpine methods `loadHealth`, `loadStats`, `loadEmptyFolders`, `loadJobs`, `openBatchModal`, `openProcessFolder`, `openProcessDay`, `openTranscribe`, `bulkDispatch`, `scanStorage`, `dispatchNow`, etc.
- `app/resources/views/ia/api-transcriptor/job-detail.blade.php`: borrar archivo.
- `app/resources/views/ia/api-transcriptor/_pipeline-diagnostics.blade.php`: borrar archivo.
- `app/app/Console/Commands/TranscriptionConsumptionSnapshot.php`: borrar archivo.
- `app/tests/harness_api_transcriptor_pending_perf.php` y `harness_api_transcriptor_modal_scope.php`: actualizar o retirar según cubran features que quedan.

**Rutas a eliminar** (en `app/routes/web.php` líneas 187-227):

```
GET    /api-transcriptor/jobs/{id}
POST   /api-transcriptor/jobs/{id}/retry
POST   /api-transcriptor/jobs/{id}/dispatch-now
POST   /api-transcriptor/jobs/bulk-dispatch
POST   /api-transcriptor/jobs/{id}/refresh-status
POST   /api-transcriptor/jobs/{id}/reprocess
POST   /api-transcriptor/jobs/{id}/cancel
DELETE /api-transcriptor/jobs/{id}
GET    /api-transcriptor/storages/{id}/files
POST   /api-transcriptor/storages/{id}/scan
POST   /api-transcriptor/storages/{id}/process-folder
POST   /api-transcriptor/storages/{id}/process-day
POST   /api-transcriptor/process-batch
POST   /api-transcriptor/scan/estimate
GET    /api-transcriptor/batch-status/{runId}
POST   /api-transcriptor/transcribe/{fileId}
GET    /api-transcriptor/jobs/{id}/status
GET    /api-transcriptor/jobs/{id}/transcript
GET    /api-transcriptor/transcribe/progress/{key}
POST   /api-transcriptor/storages/{id}/sync
GET    /api-transcriptor/health
GET    /api-transcriptor/stats
GET    /api-transcriptor/empty-folders
GET    /api-transcriptor/shm-status
GET    /api-transcriptor/latency
GET    /api-transcriptor/regulator-cause
GET    /ia/api-transcriptor/live-consumption
```

**Cron y schedule**:

- `Schedule::command('transcription:consumption-snapshot')->everyMinute()` en `app/routes/console.php` línea 156: eliminar (el snapshot alimenta el panel Consumo).

**Cache keys a limpiar** (en Redis):

```
transcriptor:stats:combined
transcriptor:health:combined
transcriptor:empty_folders:{storage_id}:max{max}
transcriptor:consumption:series:{minute_epoch}
transcriptor:tick:last_decision
```

**NO se elimina** (sigue siendo la fuente de verdad del discovery + despacho):

- Toda la pipeline backend: `TranscriptionTickCommand`, `TranscriptionPollingService`, `TranscriptionSubmitService`, `TranscriptorApiClient`, `TranscriptorSettings`, `StorageFunnelService`
- Crons: `transcription:tick`, `transcription:poll-results`, `transcription:tune`, `transcription:health-check`, `transcription:check-shm-health`, `transcription:scan-and-submit`, `transcription:retry-batch-upstream`, `transcription:backfill-corrected-audit`, `transcription:cleanup-tmpfs`, `transcription:cleanup-orphan-wav`
- Cola Redis `queues:transcription` y los 12 systemd workers `tcloud-transcription-batch-*.service`
- Tabla `transcriptions` (sigue siendo necesaria para que Mis Avisos y dashboard lean resultados)

## Non-goals

- **No** se arregla el upstream de transcripción (esa es otra conversación — el diagnóstico está en la sesión del 2026-09-15 sobre el delay de 3.7h).
- **No** se reintroduce un panel de observabilidad diferente al Consumo (la nueva superficie es solo Storages + Config).
- **No** se cambia el contrato de `transcription_enabled` (sigue siendo de un solo escritor vía `toggleStorage`).
- **No** se borran datos: la tabla `transcriptions` queda intacta con sus 8,284 `pending` y 1,904 `queued` históricos — la pipeline backend los seguirá procesando cuando el upstream vuelva a responder.
- **No** se reescribe el tick ni el regulador: este cambio es 100% UI + controller de solo lectura.