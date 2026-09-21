## MODIFIED Requirements

### Requirement: File permission check es estricto por storage_id

`FileController::checkFilePermission` SHALL conceder acceso a un archivo solo si el usuario cumple alguna de estas tres condiciones, sin excepciones por descendencia de storage ni auto-healing:

1. El usuario es admin.
2. El usuario tiene permiso (`read`/`write`/`full`) explícito sobre el `storage_provider_id` del archivo vía `User::hasStoragePermission`.
3. El `owner_id` del archivo coincide con el `id` del usuario.

(Previamente: además del check, el sistema corría `selfHealDelegationLeak` para re-forkear archivos al sub-storage del usuario. Este paso se elimina porque el sync per-storage nunca produce archivos en el storage incorrecto.)

#### Scenario: Usuario regular con acceso solo al sub-storage no accede a file en parent
- **WHEN** el usuario tiene acceso a `sub` (sub-storage) pero NO a `parent` (su storage padre en la jerarquía), y existe un file con `storage_provider_id = parent`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `false`
- **AND** el endpoint `/files/{id}/download` MUST responder HTTP 403 `{"error":"Forbidden"}`

#### Scenario: Admin siempre tiene acceso
- **WHEN** el usuario tiene `role = 'admin'` (sea jsuarez u otro admin)
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true` para cualquier file

#### Scenario: Owner del file siempre tiene acceso
- **WHEN** el archivo tiene `owner_id = user.id`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true` incluso si el archivo vive en un storage sin acceso explícito del user

#### Scenario: Usuario con acceso directo al sub-storage accede a file en el sub
- **WHEN** el usuario tiene `user_storages` row para `sub` con `permissions >= 'read'`, y existe un file con `storage_provider_id = sub`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true`

### Requirement: Descendant walk eliminado (sin self-healing)

`FileController::checkFilePermission` NO SHALL recorrer `descendants(file.storage_provider_id)` buscando un storage al que el usuario tenga acceso. NO SHALL invocar `selfHealDelegationLeak` para reparar el supuesto leak.

(Previamente: el sistema corría self-healing durante el check, asumiendo que cualquier file "leakeado" debería migrarse al sub-storage. Con el modelo simple, el sync escribe archivos en el storage correcto desde el inicio, así que el leak no existe.)

#### Scenario: Bypass de permisos eliminado
- **WHEN** el archivo vive en `parent` con `owner_id = admin_id`, el usuario regular tiene acceso solo a `sub`, y el absolute path del archivo cae bajo el base_path de `sub`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `false`
- **AND** NO se ejecuta ninguna migración del file (no hay `Log::info('self_healing.delegation_leak_migrated', ...)`)

#### Scenario: Listado de Mis Archivos y download son consistentes
- **WHEN** el listado de `FileController::index` para el sub-storage filtra correctamente por `user_storages` y NO muestra un file del parent
- **THEN** el endpoint `/files/{id}/download` para ese mismo file_id MUST responder HTTP 403 (la inconsistencia listado-vs-download queda imposible por diseño: si el listado no lo muestra, el download no puede concederlo)

### Requirement: Trade-off operacional documentado

Si un operador migró storages y nota archivos en el storage incorrecto, debe re-ejecutar el sync manualmente (botón "Actualizar") sobre el storage afectado. NO hay auto-healing nocturno.

#### Scenario: Runbook para forzar migración
- **WHEN** el operador quiere forzar la migración inmediata de un delegation leak
- **THEN** SHALL navegar a `/files?storage_id=X&sync=1&prune=1` (botón "Actualizar" en Mis Archivos con permisos full) para que `StorageSyncService::syncFolder` re-indexice el storage
- **AND** tras la migración los archivos aparecen en el storage correcto (sin auto-fork nocturno)

#### Scenario: No se reintroduce bypass por configuración
- **WHEN** el operador cambia los permisos de un usuario en `user_storages`
- **THEN** el cambio SHALL aplicar inmediatamente sin necesidad de cache invalidation o restart — la verificación de permisos se hace en cada request via `Session::get('user_id')` y `User::find(...)`

#### Scenario: Runbook para re-sincronizar storage
- **WHEN** el operador quiere forzar una re-sincronización completa de un storage
- **THEN** navega a `/files?storage_id=X&sync=1&prune=1` (botón "Actualizar" con permisos `full`) y `StorageSyncService::syncFolder` re-indexa el storage
- **AND** tras el sync, los archivos aparecen en el storage correcto (sin auto-fork)
