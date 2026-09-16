## 1. Archivo de la spec obsoleta

- [x] 1.1 Crear `openspec/specs/_archive/` si no existe
- [x] 1.2 Mover `openspec/specs/transcriptor-storage-files-srt-link/spec.md` a `openspec/specs/_archive/transcriptor-storage-files-srt-link.md`
- [x] 1.3 Agregar nota al inicio del archivo movido: `# ARCHIVED 2026-09-16: change remove-api-transcriptor-orphan-files-modal — la UI y los endpoints que esta spec documentaba fueron eliminados en 2026-09-15-simplify-api-transcriptor-to-storage-and-config y este cambio limpia el residuo.`

## 2. Eliminación del HTML del modal y botón en index.blade.php

- [x] 2.1 Eliminar el botón "Ver archivos" en `app/resources/views/ia/api-transcriptor/index.blade.php` (celda `<td class="py-3 text-right">` con `data-tour="storage-files"`, aproximadamente línea 373)
- [x] 2.2 Eliminar todo el contenedor `<div x-show="showFiles" class="fixed inset-0 bg-black/50 ...">` (modal de archivos, aproximadamente líneas 475-870)
- [x] 2.3 N/A: ya no existen `<div x-show="showBatchModal">` ni `<div x-show="showProcessConfirm">` en el archivo. Verificado con grep. El state/handlers asociados se limpian en task 3.1/3.2.

## 3. Limpieza de state Alpine y handlers en index.blade.php

- [ ] 3.1 Eliminar del return object del componente `apiTranscriptor()` los keys: `showFiles`, `currentStorage`, `filesMode`, `filesSearch`, `currentParent`, `breadcrumb`, `folders`, `filesFlat`, `filesGroups`, `filesTotal`, `filesTranscribed`, `filesLoading`, `filesSort`, `colFilters`, `selectedFileIds`, `syncing`, `bulkResult`, `processConfirmText`, `processConfirmAction`, `processAlerts`, `showProcessConfirm`, `showBatchModal`, `batchRunning`, `batchProgress`
- [ ] 3.2 Eliminar los métodos: `openFiles`, `closeFiles`, `toggleSelected`, `isSelected`, `visibleFiles`, `visibleFileCount`, `isAllVisibleSelected`, `isSomeVisibleSelected`, `clearSelection`, `selectAllVisible`, `loadFiles`, `searchFiles`, `applyColumnFilter`, `setMode`, `enterFolder`, `goUpBreadcrumb`, `confirmProcessFolder`, `confirmProcessDay`, `executeProcessConfirm`, `processFolder`, `processDay`, `syncStorageNow`, `toggleSort`, `startBatch`, `pollBatch`, `cancelBatch`
- [ ] 3.3 Verificar con grep que no quedan referencias a `showFiles`, `currentStorage`, `filesMode`, `processConfirm`, `showProcessConfirm`, `showBatchModal`, `batchRunning` en el resto del archivo

## 4. Verificación funcional

- [x] 4.1 `php artisan view:clear && php artisan cache:clear` en el servidor
- [x] 4.2 Reiniciar `php84-php-fpm` para purgar opcode cache
- [x] 4.3 Hard reload (`Ctrl+Shift+R`) en `/ia/api-transcriptor` y verificar:
  - La columna Acciones ya no muestra el botón "Ver archivos" (count=0 ✓)
  - La consola del navegador queda sin errores (sin fetch a `/storages/{id}/files`) — verificado: 0 requests a `/files`
  - El resto de la tabla (toggle Transcripción, badge snapshot, badge empty folders) sigue intacto ✓
- [x] 4.4 Verificar que el endpoint `/ia/api-transcriptor/storages/{id}/snapshot` (que sí existe) sigue retornando datos: curl retorna JSON con current/previous snapshots ✓
- [x] 4.5 Verificar que el toggle de transcripción sigue funcionando: el botón [data-tour=storage-toggle] existe y abre el modal de confirmación `storageToDisable` al clickearlo (mecanismo de protección contra apagado accidental sigue vivo). El storage id=6 (01 Caracol Tv) mantiene `transcription_enabled=true` en BD ✓
