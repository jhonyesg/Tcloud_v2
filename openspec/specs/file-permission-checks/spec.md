## Purpose

Establece la política de permisos sobre archivos del `FileController` tras la eliminación del fallback "descendant walk" del change `2026-09-17-self-healing-sync-permissions`. Garantiza que tener acceso a un sub-storage no implica acceso al parent storage, restaurando la separación "específico vs general".

## Requirements

### Requirement: File permission check es estricto por storage_id

`FileController::checkFilePermission` SHALL conceder acceso a un archivo solo si el usuario cumple alguna de estas tres condiciones, sin excepciones por descendencia de storage:

1. El usuario es admin.
2. El usuario tiene permiso (`read`/`write`/`full`) explícito sobre el `storage_provider_id` del archivo.
3. El `owner_id` del archivo coincide con el `id` del usuario.

#### Scenario: Usuario regular con acceso solo al sub-storage no accede a file en parent
- **WHEN** el usuario tiene acceso a `sub` (sub-storage) pero NO a `parent` (su storage padre en la jerarquía), y existe un file con `storage_provider_id = parent`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `false`
- **AND** el endpoint `/files/{id}/download` MUST responder HTTP 403 `{"error":"Forbidden"}`

#### Scenario: Admin siempre tiene acceso
- **WHEN** el usuario tiene `role = 'admin'` (sea jsuarez u otro admin)
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true` para cualquier file (sin importar storage ni owner)

#### Scenario: Owner del file siempre tiene acceso
- **WHEN** el archivo tiene `owner_id = user.id`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true` incluso si el archivo vive en un storage sin acceso explícito del user

#### Scenario: Usuario con acceso directo al sub-storage accede a file en el sub
- **WHEN** el usuario tiene `user_storages` row para `sub` con `permissions >= 'read'`, y existe un file con `storage_provider_id = sub`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `true`

### Requirement: Descendant walk eliminado

`FileController::checkFilePermission` NO SHALL recorrer `descendants(file.storage_provider_id)` buscando un storage al que el usuario tenga acceso.

#### Scenario: Bypass de permisos eliminado
- **WHEN** el archivo vive en `parent` con `owner_id = admin_id`, el usuario regular tiene acceso solo a `sub`, y el absolute path del archivo cae bajo el base_path de `sub`
- **THEN** `checkFilePermission(file, 'read')` MUST retornar `false`
- **AND** el `Log::info('file_controller.ancestor_permission_granted', ...)` NO SHALL escribirse (el evento queda extinto)

#### Scenario: Listado de Mis Archivos y download son consistentes
- **WHEN** el listado de `FileController::index` para el sub-storage filtra correctamente por `user_storages` y NO muestra un file del parent
- **THEN** el endpoint `/files/{id}/download` para ese mismo file_id MUST responder HTTP 403 (no debe haber caso donde la lista lo muestra y el download lo rechaza, ni donde la lista lo oculta y el download lo concede — la inconsistencia fue eliminada)

### Requirement: Trade-off operacional documentado

Si un file es realmente un "delegation leak residual" (debería estar en el sub-storage pero el sync aún no migró), el usuario con acceso solo al sub-storage NO podrá descargarlo hasta que corra el sync.

#### Scenario: Runbook para forzar migración
- **WHEN** el operador quiere forzar la migración inmediata de un delegation leak
- **THEN** puede navegar a `/files?storage_id=X&sync=1&prune=1` (botón "Actualizar" en Mis Archivos con permisos full) para que `StorageSyncService::selfHealDelegationLeak` migre el file al sub-storage correcto
- **AND** tras la migración el file aparece en el listado del sub-storage y el download funciona

#### Scenario: No se reintroduce bypass por configuración
- **WHEN** el operador cambia los permisos de un usuario en `user_storages`
- **THEN** el cambio aplica inmediatamente sin necesidad de cache invalidation o restart — la verificación de permisos se hace en cada request via `Session::get('user_id')` y `User::find(...)`
