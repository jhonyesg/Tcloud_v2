## Purpose

Capability `mis-archivos-permission-aware-listing`: define cómo el listado desde filesystem aplica filtros de permisos por usuario antes de devolver la respuesta, garantizando que un usuario solo vea archivos y carpetas en storages donde tiene acceso asignado.

## Requirements

### Requirement: Filtrado por `user_storages`
El listado SHALL filtrarse por los storages donde el usuario tiene asignación activa.

#### Scenario: Cliente con acceso a subset de storages
- **WHEN** un usuario cliente tiene filas en `user_storages` para `storage_provider_id IN [134, 49]` y nada para storage 5
- **THEN** la respuesta a `GET /files?storage_id=5` retorna `error: 'permission_denied'` y HTTP 403
- **THEN** la respuesta a `GET /files?storage_id=134` retorna el listado normal filtrado por permisos del storage
- **THEN** ningún path o nombre del storage 5 aparece en ninguna respuesta del cliente

#### Scenario: Storage personal con owner canónico
- **WHEN** un usuario `jsuarez` solicita el listado de un storage personal donde él es el owner canónico
- **THEN** ve el contenido completo del storage
- **THEN** usuarios distintos a `jsuarez` ven `error: 'permission_denied'` para ese storage

### Requirement: Permisos granulares por entrada
Cada entrada del listado SHALL incluir `permissions` y `actions` derivados de `user_storages.permissions`.

#### Scenario: Permiso `read`
- **WHEN** el usuario tiene `permissions='read'` en el storage de la entrada
- **THEN** `entry.permissions === 'read'`
- **THEN** `entry.actions` contiene `['download', 'view']` y NO contiene `['delete', 'rename', 'upload', 'share']`

#### Scenario: Permiso `write`
- **WHEN** el usuario tiene `permissions='write'` en el storage de la entrada
- **THEN** `entry.permissions === 'write'`
- **THEN** `entry.actions` contiene `['download', 'view', 'upload', 'rename']` y NO contiene `['delete', 'share']`

#### Scenario: Permiso `full`
- **WHEN** el usuario tiene `permissions='full'` en el storage de la entrada
- **THEN** `entry.permissions === 'full'`
- **THEN** `entry.actions` contiene `['download', 'view', 'upload', 'rename', 'delete', 'share']`

#### Scenario: Admin bypass
- **WHEN** el usuario es admin
- **THEN** `entry.permissions === 'admin'`
- **THEN** `entry.actions` contiene TODAS las acciones sin restricción

### Requirement: Cache de permisos por request
El guard de permisos SHALL cachear la lista de storages accesibles del usuario dentro del scope del request.

#### Scenario: Cache hit dentro del request
- **WHEN** `FilesystemPermissionGuard::filter()` se invoca múltiples veces en un mismo request (ej. listado + breadcrumb + búsqueda)
- **THEN** la segunda y siguientes invocaciones reutilizan la lista cacheada de `user_storages`
- **THEN** la query a `user_storages` se ejecuta UNA vez por request

### Requirement: Logs de denegación
Cada denegación de acceso SHALL quedar registrada con detalle suficiente para auditoría.

#### Scenario: Storage no permitido
- **WHEN** un usuario solicita listado de un storage al que no tiene acceso
- **THEN** se loguea `mis_archivos.permission_denied` con `user_id`, `storage_id`, `request_ip`, `request_path`
- **THEN** la respuesta HTTP es 403 con cuerpo JSON explicando el motivo

#### Scenario: Acción no permitida
- **WHEN** un usuario con `permissions='read'` intenta subir un archivo (acción requiere `write` o `full`)
- **THEN** el endpoint retorna 403 con mensaje "Acción 'upload' no permitida en este storage (permiso='read')"
- **THEN** se loguea `mis_archivos.action_denied` con `user_id`, `storage_id`, `action`

### Requirement: Compatibilidad con `User::hasStoragePermission`
El guard SHALL usar el helper canónico `User::hasStoragePermission()` y no reimplementar la lógica de permisos.

#### Scenario: Helper centralizado
- **WHEN** `FilesystemPermissionGuard::canAccess()` evalúa un permiso
- **THEN** delega a `User::hasStoragePermission($storageId, $permission)` que ya está probado y cubre admin bypass
- **THEN** NO existe lógica duplicada en el guard

#### Scenario: Cambio futuro en `hasStoragePermission` se propaga
- **WHEN** se agrega un nuevo nivel de permiso (ej. `audit`) a `User::hasStoragePermission`
- **THEN** el guard automáticamente lo soporta sin cambios
- **THEN** los tests del guard verifican el nuevo nivel sin código adicional
