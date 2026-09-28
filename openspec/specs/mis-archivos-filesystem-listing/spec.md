## Purpose

Capability `mis-archivos-filesystem-listing`: define cómo el módulo de Mis Archivos enumera el contenido de una carpeta leyéndolo directamente del filesystem (no de la tabla `files`), con filtrado de permisos, breadcrumb por dirname, paginación y shape de respuesta compatible con la UI vigente.

## Requirements

### Requirement: Listado directo desde filesystem
Mis Archivos SHALL obtener el contenido de una carpeta invocando `scandir()` + `stat()` sobre la ruta absoluta resuelta, no una query a la tabla `files`.

#### Scenario: Listado normal de carpeta accesible
- **WHEN** el usuario navega a una carpeta cuyo `storage.base_path` existe, es directorio legible, y no tiene ningún ancestro como montaje NFS detached
- **THEN** la respuesta AJAX contiene `files` con un array de entradas (cada una con `name`, `size`, `mime_type`, `is_folder`, `file_modified_at`, `path`)
- **THEN** cada entrada incluye `id` (file_id) si existe fila en BD para `(storage_provider_id, path)`, o `null` en caso contrario
- **THEN** cada entrada incluye `source: 'filesystem' | 'mixed'` y `has_file_id: bool`
- **THEN** la latencia p95 es ≤ 150 ms para carpetas de hasta 500 entradas en NFS local

#### Scenario: Carpeta con NFS desmontado
- **WHEN** el usuario navega a una carpeta cuyo `storage.base_path` tiene un ancestro registrado como montaje NFS esperado y ese montaje está caído
- **THEN** la respuesta incluye `error: 'mount_detached'` y `files: []`
- **THEN** el breadcrumb se devuelve igual (la ruta lógica no cambió)
- **THEN** se loguea `mis_archivos.mount_detached` con `storage_id` y `mount_point`
- **THEN** el cliente renderiza overlay "Disco no disponible" sin toast de error confuso

#### Scenario: Carpeta no existe en disco
- **WHEN** el usuario navega a una sub-ruta que `realpath()` no resuelve o `is_dir()` retorna `false`
- **THEN** la respuesta incluye `error: 'path_missing'` y `files: []`
- **THEN** se loguea `mis_archivos.fs_io_error` con la ruta intentada

#### Scenario: Listado paginado
- **WHEN** una carpeta tiene más entradas que `SystemSetting('mis_archivos.listing_limit', 500)`
- **THEN** la respuesta incluye `pagination.has_more: true` y `pagination.total` con el conteo completo
- **THEN** la UI muestra un botón "Cargar más" que invoca el siguiente page (server-side pagination opcional, client-side default)

### Requirement: Breadcrumb por segmentos de ruta
Mis Archivos SHALL calcular el breadcrumb de una sub-ruta dividiéndola por `/`, sin recurrir a queries CTE sobre la tabla `files`.

#### Scenario: Breadcrumb para 5 niveles de profundidad
- **WHEN** el usuario navega a `Bolivar/Alerta_Cartagena/28092026/28092026/file.m4a` dentro del storage 134
- **THEN** el breadcrumb devuelto contiene 5 segmentos en orden root→current: `[Bolivar, Alerta_Cartagena, 28092026, 28092026, file.m4a]`
- **THEN** el segmento raíz incluye `storage_id: 134` y `name: 'Bolivar'`
- **THEN** cada segmento tiene `has_file_id` indicando si la BD tiene la fila (puede ser `false` para archivos recién creados)
- **THEN** la latencia del breadcrumb es ≤ 10 ms (sin query a BD)

#### Scenario: Sub-ruta raíz
- **WHEN** el usuario navega a la raíz de un storage (subPath null o vacío)
- **THEN** el breadcrumb contiene un único segmento con el `name` del storage

### Requirement: Permisos antes del render
El listado SHALL filtrarse por permisos del usuario antes de devolver la respuesta.

#### Scenario: Cliente con acceso solo a storage 134
- **WHEN** un usuario cliente solicita el listado del storage 5 (al que no tiene acceso) o un sub-path bajo el storage 5
- **THEN** la respuesta contiene `files: []` y `error: 'permission_denied'` con HTTP 403
- **THEN** ningún nombre de archivo o carpeta del storage 5 es visible en la respuesta

#### Scenario: Admin siempre pasa el guard
- **WHEN** un usuario admin solicita cualquier listado
- **THEN** la respuesta contiene el listado completo del storage solicitado sin filtrado de permisos

#### Scenario: Cliente con acceso lectura pero no escritura
- **WHEN** un usuario cliente con `permissions='read'` en storage 134 solicita el listado
- **THEN** la respuesta incluye las entradas con `actions: ['download', 'view']` pero NO `actions: ['delete', 'rename', 'upload']`
- **THEN** la UI deshabilita los botones correspondientes

### Requirement: Cache de path→file_id con TTL
La resolución de `file_id` por `(storage_id, path)` SHALL cachearse en Redis con TTL configurable.

#### Scenario: Primera resolución popula cache
- **WHEN** `resolveFileId(storageId=134, path='Bolivar/28092026')` se invoca y la fila existe en BD con `id=8468976`
- **THEN** retorna `8468976` y cachea `mis_archivos:path_lookup:134:{md5(path)}` con TTL `SystemSetting('mis_archivos.path_cache_ttl')` segundos

#### Scenario: Segunda resolución dentro del TTL usa cache
- **WHEN** el mismo `resolveFileId` se invoca de nuevo dentro del TTL
- **THEN** retorna el `file_id` cacheado sin tocar la BD

#### Scenario: Invalidación por matcher
- **WHEN** `FilesystemDbMatcher` inserta una nueva fila en `files` para un `(storage_id, path)`
- **THEN** la próxima invocación de `resolveFileId` ve la nueva fila (cache miss natural al expirar el TTL, o invalidación explícita desde el matcher)

### Requirement: Shape de respuesta backward-compatible
La respuesta JSON SHALL mantener compatibilidad con la UI actual, agregando campos opcionales sin romper consumidores existentes.

#### Scenario: Campos obligatorios se preservan
- **WHEN** el cliente recibe la respuesta de listado
- **THEN** contiene `files`, `pagination`, `breadcrumbs` con los mismos nombres de campos que la versión BD-primero
- **THEN** `meta.fs_primary` indica el modo activo (true/false) para que la UI muestre badge

#### Scenario: Campos nuevos son opcionales
- **WHEN** un consumidor legacy lee la respuesta y solo inspecciona `files[i].name`, `files[i].is_folder`, `files[i].size`, `files[i].file_modified_at`
- **THEN** esos campos siguen presentes y con el mismo tipo y semántica
