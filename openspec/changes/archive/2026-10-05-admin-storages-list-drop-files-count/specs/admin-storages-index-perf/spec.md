## MODIFIED Requirements

### Requirement: Cache del listado de Admin Storages

El sistema SHALL cachear la respuesta de `GET /admin/storages` (lista de storages) en Redis con TTL 60 s. Key: `admin:storages:index`.

El TTL SHALL ser legible desde `SystemSetting('admin_storages_cache_ttl', 60)`. Rango valido `[0, 600]`. Valor `0` = bypass.

La respuesta del listado SHALL contener **únicamente** los atributos propios de `storage_providers`. El sistema MUST NOT computar ni incluir el atributo derivado `files_count` en esta respuesta, para no tocar la tabla `files` (1.36M filas) al listar.

#### Scenario: Cache hit
- **GIVEN** la key existe y fue poblada hace menos de 60 s
- **WHEN** el navegador hace `GET /admin/storages`
- **THEN** el sistema retorna el JSON cacheado
- **AND** la respuesta es `< 200 ms`

#### Scenario: Listado sin conteo de archivos
- **WHEN** el navegador hace `GET /admin/storages` con la cache fría
- **THEN** el sistema ejecuta `SELECT` sobre `storage_providers` sin subconsultas contra `files`
- **AND** la respuesta NO incluye la clave `files_count` en ninguna fila
- **AND** la respuesta se sirve en `< 250 ms`

#### Scenario: Invalidacion al toggleStorage
- **GIVEN** la key esta poblada
- **WHEN** se ejecuta `POST /api-transcriptor/storages/{id}/toggle`
- **THEN** la key `admin:storages:index` es borrada
- **AND** el siguiente GET ejecuta compute fresco

#### Scenario: Invalidacion al mutar storage_providers
- **WHEN** cualquier mutacion en `storage_providers.base_path` o `transcription_enabled`
- **THEN** el sistema invalida `admin:storages:index`

## ADDED Requirements

### Requirement: Conteo de archivos bajo demanda para un storage individual

El sistema SHALL exponer el conteo de archivos de un storage individual a través de `GET /admin/storages/{id}` (`StorageProviderController::show`), que SHALL incluir `files_count` para ese único storage. El frontend SHALL consumir este endpoint bajo demanda cuando necesita el conteo (ej. modal de confirmación de borrado), y MUST NOT solicitarlo para el listado completo.

#### Scenario: Conteo de un storage individual
- **WHEN** el admin abre el modal de confirmación de borrado de un storage
- **THEN** el frontend solicita `GET /admin/storages/{id}`
- **AND** la respuesta incluye `files_count` con el número exacto de archivos de ese storage
- **AND** la petición tarda `< 100 ms` para el storage de mayor tamaño

#### Scenario: Fallo del conteo bajo demanda no bloquea el borrado
- **WHEN** el request `GET /admin/storages/{id}` falla o no responde
- **THEN** el modal muestra un texto genérico ("los archivos asociados a este storage") sin inventar un número
- **AND** el botón "Eliminar" permanece habilitado
