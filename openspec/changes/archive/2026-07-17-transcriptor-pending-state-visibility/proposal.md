## Why

El módulo **API Transcriptor** muestra `Pendientes: 0` y `Completados: 0` en sus sub-tabs aunque la API tiene jobs activos en `processing`/`queued`. La causa raíz es estructural: el frontend filtra por `state ∈ {queued, processing}` para "Pendientes" y `state ∈ {done, error, dead}` para "Completados", pero el estado de BD `pending` (Transcription creada pero todavía no enviada a la API externa, sin `job_id`) **no entra en ninguno de los dos**. Los jobs que están REALMENTE sin enviar son invisibles para el admin.

Adicionalmente, la propia existencia de un volumen significativo de filas con `state='pending'` por periodos largos es síntoma probable de que el pipeline de envío (`scan-and-submit` o dispatch por cola) no está consumiéndolas, lo cual es un bug operativo distinto del bug de UI.

## What Changes

- **Visibilidad en UI** — Agregar el estado `pending` (BD) a la sub-tab "Pendientes" del módulo API Transcriptor, para que las `Transcription` sin `job_id` sean visibles y accionables. Acciones permitidas en esa fila: "Enviar ahora" (síncrono via `TranscriptionSubmitService`) y "Cancelar" (borrar la fila).
- **Diagnóstico** — Agregar un endpoint o augmenter el existente `/ia/api-transcriptor/stats` para exponer contadores por estado (`pending`, `queued`, `processing`, `done`, `error`, `dead`) y la edad del `pending` más viejo. Esto permite detectar si el pipeline estático.
- **Investigación dirigida** — Auditar el flujo que crea `state='pending'` (`process-folder`, `process-day`, `retry`, `reprocess`, `BulkDispatchTranscriptionJob`) y el camino de consumo (`transcription:scan-and-submit`, `ConvertAndTranscribeJob`). Si se identifica un bug de no-consumo, se marca como hallazgo separado y se propone fix adicional **fuera de este change** (este change solo expone el problema; no toca el scheduler para mantener el scope acotado).
- **Filtro `pending` opcional** — El `<select x-model="stateFilter">` (líneas 564-570) gana la opción `pending` para que el admin pueda filtrar la lista unificada explícitamente.

## Capabilities

### New Capabilities
- `transcriptor-state-visibility`: define cómo la UI del módulo API Transcriptor clasifica y muestra cada `state` de `Transcription`, y qué acciones son válidas en cada uno.

### Modified Capabilities
- (ninguno — el spec `transcription-api-orchestrator` define los estados BD pero no la UI; el cambio es puramente de presentación + visibilidad. Si la investigación descubre un cambio de requisito en el orquestador, se propondrá un change aparte.)

## Impact

- **Frontend**: `app/resources/views/ia/api-transcriptor/index.blade.php`
  - Computed `jobsPendingCount` (línea 1138-1140): incluir `state === 'pending'`.
  - Visibilidad de fila (línea 683): incluir `state === 'pending'` en la rama "Pendientes".
  - `<select x-model="stateFilter">` (líneas 564-570): agregar `<option value="pending">`.
  - Botones de acción (líneas 712-727): diferenciar la rama `pending` (sin `job_id`) — debe mostrar "Enviar ahora" (sync via `TranscriptionSubmitService`) y "Cancelar" (DELETE de la fila).
  - Action bar `dispatchableJobsCount()` (línea 1251) y `dispatchableJobs()` (línea 1248): decidir si `pending` es dispatchable. Recomendación: SÍ, porque ya la UI hace `transcribeFile` síncrono desde el botón del modal "Ver archivos".
- **Backend**: `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`
  - `indexData()` (línea 26): sin cambios funcionales, pero al `cancelJob()` (buscar método) hay que permitir cancelar filas en `state='pending'` que NO tienen `job_id` upstream (DELETE local en lugar de POST a la API externa).
  - `stats()` método: agregar contadores desglosados por estado y `oldest_pending_age_seconds`.
- **Diagnóstico (sin tocar lógica de negocio)**: agregar query Tinker/artisan one-shot para listar `state='pending'` con `file_id`, `created_at`, `started_at`, `storage_provider_id` — útil para investigar causa raíz antes de proponer fix de scheduler.
- **No requiere migración** — solo lectura/escritura sobre tablas existentes (`transcriptions`).

## Non-goals

- NO se modifica el comando `transcription:scan-and-submit`, el job `ConvertAndTranscribeJob`, ni la cola Redis en este change. El alcance es **mostrar y diagnosticar**, no arreglar el scheduler. Si la auditoría revela un bug aguas abajo, se abre un change aparte con su propio proposal/design/tasks.
- NO se renombra el estado BD `pending` ni se cambia el modelo `Transcription`. Solo se altera la presentación.
- NO se agregan permisos nuevos. La ruta sigue detrás del middleware `auth`.
- NO se tocan `transcription:poll-results`, `webhooks/transcription`, ni `TranscriptionSubmitService`.
