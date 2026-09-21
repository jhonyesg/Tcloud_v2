## Purpose

Capacidad operativa de limpieza de datos corruptos en `files` y `user_storages` que quedaron persistidos tras la reversión del módulo Mis Archivos al baseline de agosto (`restore-mis-archivos-august`). Cubre el flujo de auditoría vía `--dry-run`, la aplicación con snapshot respaldable, y la verificación post-fix.

## ADDED Requirements

### Requirement: Comando para purgar carpetas fantasma de `files`

El sistema SHALL proveer el comando `php artisan files:purge-ghost-folders` que identifica y elimina de `files` las filas de tipo carpeta (`is_folder=true`) que cumplen simultáneamente:
- `file_modified_at IS NULL` (nunca pasaron por un `scandir` real), Y
- su `path` no existe como directorio dentro de `storage_providers.base_path` del storage al que pertenece la fila.

El comando SHALL operar por defecto en modo `dry-run`: lista las carpetas candidatas sin modificar nada, agrupadas por `storage_provider_id`, mostrando `id`, `name`, `path`, `created_at`. El modo `--apply` ejecuta la eliminación.

El comando SHALL crear antes del `DELETE` una tabla snapshot `files_ghost_pre_purge_<YYYYMMDDHHMMSS>` que contenga una copia exacta de las filas a borrar, para auditoría y rollback. La tabla SHALL persistir tras el comando (no se borra automáticamente).

El comando SHALL NO modificar filas de tipo archivo (`is_folder=false`), ni carpetas con `file_modified_at IS NOT NULL`, ni carpetas cuya `path` sí exista en disco.

El comando SHALL respetar `--storage=<id>` para limitar el alcance a un solo `storage_provider_id`, y `--yes` para omitir la confirmación interactiva. Sin `--apply`, ningún cambio es persistente.

#### Scenario: Dry-run no modifica la base de datos
- **WHEN** el operador ejecuta `files:purge-ghost-folders` sin la opción `--apply`
- **THEN** el comando imprime la lista de carpetas candidatas agrupadas por storage
- **THEN** ningún INSERT/UPDATE/DELETE se ejecuta contra `files`
- **THEN** no se crea ninguna tabla snapshot

#### Scenario: Apply elimina las carpetas con respaldo
- **WHEN** el operador ejecuta `files:purge-ghost-folders --apply --yes`
- **THEN** se crea la tabla `files_ghost_pre_purge_<ts>` con las filas condenadas
- **THEN** las filas condenadas se eliminan de `files` en chunks
- **THEN** el comando imprime cuántas filas eliminó y confirma la existencia del snapshot
- **AND** la caché de listado del storage afectado se invalida vía `StorageSyncService::invalidateFolderCache`

#### Scenario: Carpetas con file_modified_at quedan intactas
- **WHEN** existe una carpeta en `files` con `file_modified_at IS NOT NULL` aunque el path no esté en disco
- **THEN** el comando NO la incluye entre las candidatas a purgar
- **AND** no se elimina

#### Scenario: Alcance por storage
- **WHEN** el operador ejecuta `files:purge-ghost-folders --storage=5`
- **THEN** el análisis se limita a `storage_provider_id = 5`
- **AND** el resto de storages quedan sin tocar

#### Scenario: Confirmación interactiva sin --yes
- **WHEN** el comando está en modo `--apply` sin la opción `--yes`
- **THEN** se imprime el conteo de candidatas y se pide confirmación interactiva
- **AND** si el operador declina, no se elimina nada

### Requirement: Comando para corregir visibilidad cruzada de personales

El sistema SHALL proveer el comando `php artisan user-storages:fix-personal-visibility` que audita la tabla `user_storages` para todos los `storage_providers` con `is_personal=true` y propone dejar una sola fila por storage: la del usuario cuyo `username` coincide con el último segmento de `base_path` (formato `/home/www/Usuarios_tcloud/<username>/`).

El comando SHALL identificar al usuario canónico resolviendo el nombre del segmento de `base_path` contra `users.username` (case-sensitive). Si no existe ese usuario, SHALL reportar el caso como error sin proponer diff.

El comando SHALL operar por defecto en modo `dry-run`: lista cada storage personal con su `user_storages` actual y las filas que se eliminarían en `--apply`. El modo `--apply` ejecuta el borrado.

El comando SHALL NO modificar `user_storages` de storages con `is_personal=false`.

#### Scenario: Dry-run lista el diff por storage
- **WHEN** el operador ejecuta `user-storages:fix-personal-visibility`
- **THEN** por cada storage personal con más de un usuario asignado se imprime: storage_id, nombre, base_path, usuarios actuales, usuario canónico resuelto, filas a conservar, filas a eliminar
- **AND** ningún DELETE se ejecuta

#### Scenario: Apply deja un solo usuario por personal
- **WHEN** el operador ejecuta `user-storages:fix-personal-visibility --apply --yes` sobre un storage personal con dos asignaciones (`Stakeholders` y `jsuarez`, dueño canónico `StakeholdersPrensa`)
- **THEN** se elimina la fila de `user_storages` cuyo `user_id` NO es el dueño canónico
- **AND** el dueño canónico mantiene su fila
- **AND** la cantidad de filas eliminadas se imprime al final

#### Scenario: Storage sin usuario canónico registrado se reporta sin aplicar
- **WHEN** un storage personal tiene `base_path = /home/www/Usuarios_tcloud/Foo` y `users.username = 'Foo'` no existe
- **THEN** el comando imprime el error de resolución
- **AND** no se ejecuta ningún cambio para ese storage
- **AND** los storages con usuario canónico válido sí se procesan

#### Scenario: Storages no personales se ignoran
- **WHEN** el comando se ejecuta con storages de tipo local, s3 y personal mezclados
- **THEN** solo se auditan los que tienen `is_personal=true`
- **AND** los demás quedan sin tocar

### Requirement: Verificación post-purga

Ambos comandos SHALL imprimir al final una tabla de verificación con:
- (para `files:purge-ghost-folders`) cantidad de carpetas restantes con `file_modified_at IS NULL` y `parent_id IS NULL` por storage.
- (para `user-storages:fix-personal-visibility`) cantidad de `user_storages` por storage personal (debe ser 0 o 1 por storage).

#### Scenario: Verificación reporta conteos esperados
- **WHEN** el operador ejecuta cualquiera de los dos comandos tras la limpieza
- **THEN** imprime la tabla de verificación con el conteo actual vs el esperado
- **AND** si el conteo esperado no es 0/1, imprime WARNING en lugar de OK
