## Purpose

Restablece el modelo simple de Mis Archivos vigente entre el 27-jul-2026 (commit `a15e5f3`) y el 19-ago-2026 (commit `bb71b9c`): cada `storage_provider` mantiene su propio listado, cada `user_storage` permite ver el storage, y cada archivo físico se representa con exactamente una fila en `files`. Este baseline es la línea base anti-duplicidad probada en producción, sin la complejidad cross-storage introducida en septiembre.

## ADDED Requirements

### Requirement: Listado por storage sin resolución cross-storage

El listado de archivos en Mis Archivos SHALL filtrar por `storage_provider_id IN (user.user_storages.storage_provider_id)` sin invocar `StorageSyncService::resolveListingTargets()` ni `StorageHierarchyService`. Cada storage navega de forma independiente.

#### Scenario: Usuario regular navega su storage asignado
- **WHEN** el usuario tiene `user_storages` para `storage_id = 5` con `permissions = 'read'`, y abre `/files?storage_id=5`
- **THEN** el listado muestra los archivos cuyo `storage_provider_id = 5`
- **AND** NO se invocan servicios cross-storage ni se intenta mergear con sub-storages

#### Scenario: Usuario con múltiples storages ve cada uno por separado
- **WHEN** el usuario tiene `user_storages` para storages `5`, `6`, `7` con `permissions = 'full'`
- **THEN** puede navegar cada storage independientemente desde el storage switcher
- **AND** cada navegación carga únicamente archivos del storage seleccionado

### Requirement: Identidad física única por storage

Cada par `(storage_provider_id, path)` SHALL tener exactamente una fila en `files`, garantizada por el índice UNIQUE `files_storage_provider_id_path_unique`. No existen mirror rows, no existe `canonical_folder_id`, no existe `merged_into_id`.

#### Scenario: Intento de duplicar un path en el mismo storage
- **WHEN** `FileRegistry::ensure()` se llama dos veces con `(storage_provider_id=5, path='foo.mp3')`
- **THEN** la segunda llamada retorna el row existente sin crear duplicado (captura `SQLSTATE 23505` y relee)

#### Scenario: Mismo path en dos storages distintos
- **WHEN** storage `5` y storage `7` contienen físicamente un archivo llamado `Portafolio/nota.txt`
- **THEN** `files` tiene DOS filas independientes: una con `storage_provider_id=5, path='Portafolio/nota.txt'` y otra con `storage_provider_id=7, path='Portafolio/nota.txt'`
- **AND** el listado de storage 5 solo muestra la fila de storage 5; el listado de storage 7 solo muestra la de storage 7

### Requirement: Sync per-storage sin auto-creación de mirrors

`StorageSyncService::syncFolder()` SHALL operar solo dentro del `storage_provider` recibido. NO SHALL crear filas adicionales en storages padre para representar contenido de sub-storages. NO SHALL invocar `ensureSubstorageFolderChain` ni `findMoreSpecificStorage`.

#### Scenario: Sync de sub-storage no crea filas en parent
- **WHEN** el operador ejecuta `storage:sync --storage=7` para un sub-storage cuyo padre es `5`
- **THEN** `files` solo recibe INSERTs/UPDATEs con `storage_provider_id = 7`
- **AND** el storage `5` NO recibe ninguna fila nueva como resultado del sync de storage 7

### Requirement: Sin trash per-file, sin availability tracking

`files` NO SHALL tener columnas `is_trashed`, `deleted_at`, `availability_state`, `last_verified_at`, `missing_since_at`. La papelera y el monitoreo de disponibilidad quedan fuera de este módulo (pueden existir como features globales independientes si se necesitan).

#### Scenario: Borrado de archivo es destructivo
- **WHEN** el usuario borra un archivo vía `DELETE /files/{id}`
- **THEN** la fila de `files` se elimina físicamente (no soft delete)
- **AND** las FKs `ON DELETE CASCADE` propagan a `transcriptions.file_id` y `shares.file_id`

### Requirement: Comandos operativos del módulo

El módulo SHALL exponer los comandos `storage:sync`, `files:dedupe`, y `SyncStorage` (alias) como puntos de entrada operativos, según la documentación del change archivado `2026-08-20-2026-07-27-files-duplication-fix`.

#### Scenario: Operador lanza sync manual
- **WHEN** el operador corre `php artisan storage:sync --storage=5`
- **THEN** el sync procesa el storage 5 respetando FileRegistry, PruneGuard y MountGuard
- **AND** el log emite el reporte estándar `{scanned, added, updated, removed, errors}`
