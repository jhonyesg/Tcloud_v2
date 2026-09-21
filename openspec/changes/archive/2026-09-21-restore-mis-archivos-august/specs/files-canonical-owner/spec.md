## REMOVED Requirements

### Requirement: Columna `files.canonical_folder_id` y owner canónico por storage
**Reason**: El modelo "una identidad física = una fila canónica + N mirror rows" no se materializa. La identidad física ya se resuelve por `(storage_provider_id, path)` UNIQUE; introducir una segunda fila para el mismo archivo generó 1.455.875 mirror rows con 94% rotas. La columna y los helpers asociados se retiran.
**Migration**: No hay migración de datos adicional: las filas con `canonical_folder_id IS NOT NULL` se borran en `files:repair-mirror-targets --apply` antes del DROP COLUMN. Los call sites que dependían de `StorageProvider::canonicalOwnerId` migran al check directo `User::hasStoragePermission(file.storage_provider_id, $perm)`.

### Requirement: Helper `StorageProvider::canonicalOwnerId(int $storageId)`
**Reason**: La regla "owner canónico por menor user_storages.id" se introduce para resolver ambigüedad entre múltiples usuarios con `permissions='full'` sobre el mismo storage. Con el modelo simple de agosto, esta ambigüedad se resuelve con `checkFilePermission` que consulta directamente `user_storages` por `storage_provider_id`. El helper queda sin uso.
**Migration**: Reemplazar `StorageProvider::canonicalOwnerId($id)` por la consulta directa equivalente en el callsite (o por la lógica existente en `FileController::checkFilePermission`).

### Requirement: Regla de extracción de username desde base_path
**Reason**: La regla "si storage es `is_personal`, extraer username del último segmento de `base_path`" solo aplicaba cuando había ambigüedad de ownership. Sin el helper canónico, no hay consumidor de esa extracción.
**Migration**: Si en el futuro se necesita ownership per-storage personal, se reintroduce como feature independiente con su propio spec.
