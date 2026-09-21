## REMOVED Requirements

### Requirement: `StorageSyncService::resolveListingTargets` y listado cross-storage
**Reason**: El método intentaba mergear los listados del storage padre con los sub-storages cuando navegabas desde el padre. En producción (verificado 2026-09-19) producía listados vacíos porque las mirror rows estaban rotas. El modelo simple de agosto navega cada storage de forma independiente.
**Migration**: El listado vuelve a `File::where('storage_provider_id', $storageId)->where('parent_id', $parentId)`. Si el operador quiere ver archivos que viven en un sub-storage, navega explícitamente al sub-storage desde el storage switcher.

### Requirement: Garantía de raíz de sub-storage
**Reason**: El spec original exigía que `relativeToSub === ''` (caso raíz de sub-storage) devolviera `parent_id = null` para que `GET /files?storage_id=37&parent_id=null` listara la raíz del sub-storage. Esto solo se justificó porque los mirrors cross-storage lo bloqueaban; sin mirrors, la consulta per-storage funciona nativamente.
**Migration**: No requerida. La consulta `whereNull('parent_id')` funciona directamente.

### Requirement: Adopción del storage de la fila devuelta en el frontend
**Reason**: El frontend adoptaba `currentStorage` del primer row devuelto porque el cross-storage listing podía mezclar storages. Con listing per-storage, el `storage_id` de la URL siempre coincide con el storage del listado.
**Migration**: El `navigateToFolder()` (en `files/index.blade.php`) ya no necesita sobreescribir `currentStorage` — la variable es derivada del query param.
