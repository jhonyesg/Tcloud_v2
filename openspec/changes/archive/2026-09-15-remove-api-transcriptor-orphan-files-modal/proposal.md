## Why

El módulo `/ia/api-transcriptor` quedó con un botón "Ver archivos" en la columna Acciones y todo su modal asociado apuntando a endpoints HTTP que **no existen** desde el change archivado `2026-09-15-simplify-api-transcriptor-to-storage-and-config`. Resultado: el operador ve "Esta carpeta está vacía · 0 carpetas, 0 archivos" cuando hace click, y la columna Acciones contiene un control roto que invita a error. La spec `transcriptor-storage-files-srt-link` describe exactamente esta funcionalidad huérfana que el código ya no implementa.

## What Changes

- **Eliminar** el botón "Ver archivos" de la columna Acciones en `app/resources/views/ia/api-transcriptor/index.blade.php` (línea ~373).
- **Eliminar** el modal completo de "Archivos — Explorar" (líneas ~475-870 aprox): contenedor `x-show="showFiles"`, modos Explorar/Hoy/Ayer, breadcrumb, listado de carpetas/archivos, búsqueda, footer, checkbox "Procesar carpeta" y "Procesar HOY/AYER", confirmaciones, acciones de selección.
- **Eliminar** los handlers Alpine asociados: `openFiles`, `closeFiles`, `toggleSelected`, `isSelected`, `visibleFiles`, `visibleFileCount`, `isAllVisibleSelected`, `isSomeVisibleSelected`, `loadFiles`, `searchFiles`, `applyColumnFilter`, `setMode`, `enterFolder`, `goUpBreadcrumb`, `confirmProcessFolder`, `confirmProcessDay`, `executeProcessConfirm`, `processFolder`, `processDay`, `syncStorageNow`, `clearSelection`, `selectAllVisible`, `sortBy`.
- **Eliminar** el state Alpine de archivos: `showFiles`, `currentStorage`, `filesMode`, `filesSearch`, `currentParent`, `breadcrumb`, `folders`, `filesFlat`, `filesGroups`, `filesTotal`, `filesTranscribed`, `filesLoading`, `filesSort`, `colFilters`, `selectedFileIds`, `syncing`, `bulkResult`, `processConfirmText`, `processConfirmAction`, `processAlerts`, `showProcessConfirm`, `showBatchModal`, `batchRunning`, `batchProgress`.
- **Marcar como archivada** la spec `transcriptor-storage-files-srt-link` (mover a `openspec/specs/_archive/transcriptor-storage-files-srt-link.md`) — sus requisitos documentan la funcionalidad que se elimina.

## Non-goals

- No reintroducir navegación de archivos por storage ni reenvío de fallidos manual desde la UI (decisiones de producto ya tomadas en el change archivado; cualquier reversión sería un change nuevo).
- No tocar el backend: `StorageSyncService`, `TranscriptionBulkDispatchService`, `TranscriptorApiClient::retryBatchUpstream()` siguen vivos y son consumidos por comandos Artisan / cron. Solo el consumo desde esta UI específica se retira.
- No mover la columna Acciones a otra ubicación ni reorganizar la tabla; la columna queda con el resto del row intacto (badge `snapshot`, badge `emptyFolders`).

## Capabilities

### New Capabilities
*(ninguna — es un refactor que elimina UI, no introduce capacidad nueva)*

### Modified Capabilities
*(ninguna — la spec `transcriptor-storage-files-srt-link` se archiva, no se modifica delta)*

Este change es refactor puro: `skip_specs: true` en `.openspec.yaml`.

## Impact

- `app/resources/views/ia/api-transcriptor/index.blade.php`: ~500 líneas eliminadas (botón Acciones + modal + handlers + state).
- `openspec/specs/transcriptor-storage-files-srt-link/spec.md` → `openspec/specs/_archive/transcriptor-storage-files-srt-link.md`.
- Consola del navegador: ya no habrá `fetch` a `/storages/{id}/files` que devolvía 404 silencioso.
- Operador: la columna Acciones de Storages ya no muestra el botón roto. La columna queda vacía para esa fila (transcripción toggle sigue funcionando en la columna previa). Si en el futuro se quiere re-introducir drill-down, será un change nuevo.
- Sin migración de BD, sin tocar routes (los endpoints muertos ya no están registrados desde 2026-09-15), sin tocar cron jobs.
