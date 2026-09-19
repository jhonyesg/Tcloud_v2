## Purpose

Garantiza que las descargas funcionen correctamente aunque el sistema tenga data drift (delegation leak): si un file row tiene `storage_provider_id` en el parent pero el usuario solo tiene acceso al sub-storage (descendant), el permiso pasa via descendant fallback. Defense in depth mientras el self-healing sync migra los files a su storage correcto.

## Requirements

### Requirement: Permission check cae al descendant storage cuando el storage directo falla

`FileController::checkFilePermission` SHALL, tras el check directo de `user.hasStoragePermission(file.storage_provider_id)`, iterar sobre el árbol DESCENDIENTE del storage del file (via `StorageHierarchyService::descendants()`). Si CUALQUIER descendant storage está en `user_storages` con el permiso requerido, SHALL retornar true.

#### Scenario: User con acceso al sub-storage, file con storage_provider_id en parent (delegation leak)

- **WHEN** `f.storage_provider_id = P` (parent, ej. 00 Discos = storage 5)
- **AND** `f.path` es descendiente de `S.base_path` para algún sub-storage `S` con `S.parent_storage_id = P`
- **AND` user "Punto" tiene `user_storages.storage_provider_id = S` con `permissions='read'`
- **AND** user "Punto" NO tiene `user_storages.storage_provider_id = P`
- **AND** user "Punto" hace click en `f` para descargar
- **THEN** `FileController::download` SHALL retornar 200 OK con el archivo (no 403 Forbidden)
- **AND** SHALL loggear `file_controller.ancestor_permission_granted` con `file_id`, `file_storage_id`, `granted_via_storage_id`, `user_id`

#### Scenario: User sin acceso a ningún descendant

- **WHEN** user "Externo" NO tiene `user_storages` con `storage_provider_id` = `f.storage_provider_id` ni a ninguno de sus descendants
- **THEN** SHALL retornar 403 Forbidden (comportamiento existente preservado)

#### Scenario: Admin bypass

- **WHEN** user es admin (cualquier role de admin)
- **THEN** SHALL retornar true inmediatamente sin chequear descendant fallback (optimización: el admin ya tiene acceso total)

#### Scenario: Performance: cache de descendant tree

- **WHEN** `checkFilePermission` consulta `StorageHierarchyService::descendants()` para el mismo `storage_provider_id` múltiples veces en el mismo proceso
- **THEN` SHALL usar cache memoizado (memoria del proceso) — segunda llamada retorna sin query
- **AND` SHALL NO invalidar el cache dentro del mismo proceso (TTL configurable)

### Requirement: Permission check NO aplica ancestor fallback para admins

`FileController::checkFilePermission` SHALL continuar retornando true inmediatamente si `user.isAdmin()` es true, sin chequear descendant storage. Los admins tienen acceso total por convención.

#### Scenario: Admin descarga file con storage_provider_id arbitrario

- **WHEN** user tiene role='admin' (cualquier variante)
- **AND** `f.storage_provider_id` es CUALQUIER storage (incluso uno no relacionado al user)
- **THEN** SHALL retornar true (admin bypass)
- **AND** SHALL NO ejecutar descendant fallback (optimización: el admin ya tiene acceso)

### Requirement: PublicShareController::isDescendantOf usa fallback cuando el parent_id chain está roto

Cuando `isDescendantOf($file, $ancestor)` walking up por `parent_id` no llega al `$ancestor` (chain roto: parent_id NULL o huérfano), SHALL retornar true SOLO si `$file.path` empieza con `$ancestor.path`. Esto evita errores "Invalid parent folder" en uploads/downloads de shared links cuando el chain está roto.

NO usar `storage_provider_id` igual como heuristica porque dos archivos siblings (ambos en el root del mismo storage, ambos con parent_id=NULL) pasarían incorrectamente — son siblings, no descendientes uno del otro.

#### Scenario: Parent_id chain roto pero path bajo ancestor

- **WHEN** walking up desde `$file.parent` no alcanza `$ancestor` (chain roto o NULL)
- **AND** `$file.path` empieza con `$ancestor.path` (mismo prefijo)
- **THEN** SHALL retornar true (el path absoluto indica descendencia lógica aunque la cadena de parent_id este rota)

#### Scenario: Siblings en mismo storage NO son descendientes uno del otro

- **WHEN** `$file.storage_provider_id === $ancestor.storage_provider_id`
- **AND** ambos tienen `parent_id IS NULL` (chain roto, no encadenados)
- **AND** `$file.path` NO empieza con `$ancestor.path`
- **THEN** SHALL retornar false (siblings no son descendientes — la heuristica por storage_provider_id es incorrecta)

#### Scenario: Chain normal sigue funcionando

- **WHEN** walking up desde `$file.parent` alcanza `$ancestor` via la cadena de `parent_id`
- **THEN** SHALL retornar true (comportamiento previo preservado, sin cambio de semantica para chains correctos)

#### Scenario: File en storage DIFERENTE con path no relacionado retorna false

- **WHEN** `$file.storage_provider_id !== $ancestor.storage_provider_id`
- **AND** `$file.path` no empieza con `$ancestor.path`
- **AND** walking up no alcanza `$ancestor`
- **THEN** SHALL retornar false (no es descendiente legitimo)
