# storage-readability-untraversable-children Specification

## Purpose

Detecta y opcionalmente repara directorios hijos directos del `base_path` de un `StorageProvider` que son inaccesibles al usuario PHP-FPM por permisos restrictivos del filesystem. Complementa — sin reemplazar — la spec `storage-health-and-reconcile`, que cubre accesibilidad del root del storage y dispatch de reconciliación al remontar.

## ADDED Requirements

### Requirement: Audit detecta hijos no traversables por permisos

`storage:audit-ownership` SHALL reportar, además de la categoría existente `owner_mismatch`, una nueva categoría `untraversable` que liste los hijos directos del `base_path` de cada `StorageProvider` activo donde `is_dir($path)` resuelve verdadero pero `@opendir($path)` falla con `EACCES`. Cada hallazgo SHALL cruzar contra la tabla `files` para confirmar que la BD conoce contenido real bajo ese path; SHALL omitirse el registro cuando la BD no contiene ninguna fila con `storage_provider_id` igual y `path` que arranque con el nombre del hijo.

#### Scenario: Storage con un hijo bloqueado pero contenido en BD

- **WHEN** `storage:audit-ownership` corre sobre un storage cuyo `base_path` tiene un hijo `Casanare` con modo 700 y la BD tiene 593 filas con `storage_provider_id` igual y `path LIKE 'Casanare/%'`
- **THEN** SHALL emitir un hallazgo en categoría `untraversable` con `path`, `mode`, `owner`, `group`, y conteo de filas en BD afectadas
- **AND** SHALL NO emitirlo si la BD no tiene filas bajo ese path

#### Scenario: Storage con hijo bloqueado pero sin contenido en BD

- **WHEN** `storage:audit-ownership` corre sobre un storage cuyo `base_path` tiene un hijo `System Volume Information` con modo 700 y la BD no contiene filas bajo ese path
- **THEN** SHALL NO emitir el hallazgo (se considera metadata inerte)

#### Scenario: Storage con todos los hijos traversables

- **WHEN** `storage:audit-ownership` corre sobre un storage cuyos hijos directos son todos legibles para el usuario PHP-FPM
- **THEN** SHALL emitir el hallazgo `untraversable: 0` para ese storage

### Requirement: Denylist duro de metadata inerte

`storage:audit-ownership` SHALL excluir de la categoría `untraversable` los nombres de directorio que coincidan con la denylist: `System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, y cualquier nombre que comience con `.`. La denylist SHALL ser exhaustiva y no aceptar configuración por storage; es invariante del sistema operativo.

#### Scenario: Hijo System Volume Information no aparece como untraversable

- **WHEN** `storage:audit-ownership` corre sobre un storage con un hijo `System Volume Information` con modo 700
- **THEN** SHALL NO emitir el hallazgo en ninguna categoría
- **AND** SHALL contar el hijo en la categoría `metadata_skip`

#### Scenario: Hijo .Recycle_bin no aparece como untraversable

- **WHEN** `storage:audit-ownership` corre sobre un storage con un hijo `.Recycle_bin` con modo 700
- **THEN** SHALL NO emitir el hallazgo en ninguna categoría
- **AND** SHALL contar el hijo en la categoría `metadata_skip`

### Requirement: Persistencia de métricas por storage

`storage:audit-ownership` SHALL persistir, al finalizar la corrida para cada storage, los conteos `untraversable_count` y `owner_mismatch_count` en `SystemSetting` con las claves `storage_health:<storage_id>:untraversable_count` y `storage_health:<storage_id>:owner_mismatch_count` respectivamente. SHALL actualizar `SystemSetting('storage_health:audit_finished_at')` con la marca temporal de la última corrida exitosa. La persistencia SHALL NO ocurrir si la corrida terminó en error o fue saltada por el lock de concurrencia.

#### Scenario: Audit exitoso actualiza métricas

- **WHEN** `storage:audit-ownership` completa una corrida que procesó 4 storages
- **THEN** SHALL existir `SystemSetting('storage_health:55:untraversable_count')` con valor entero igual a los hallazgos del storage 55
- **AND** SHALL existir `SystemSetting('storage_health:audit_finished_at')` con timestamp ISO 8601 de la finalización

#### Scenario: Audit saltado por lock de concurrencia

- **WHEN** `storage:audit-ownership` no obtiene el lock `storage:ownership-audit:lock` y sale inmediatamente
- **THEN** SHALL NO sobrescribir las métricas existentes
- **AND** SHALL NO actualizar `audit_finished_at`

### Requirement: Repair requiere UID 0

`storage:repair-ownership` SHALL abortar inmediatamente con código de salida 1 si el UID efectivo del proceso no es 0. El mensaje SHALL indicar cómo invocar el comando con privilegios (`sudo php artisan storage:repair-ownership ...`). SHALL NO ejecutar ninguna mutación ni siquiera el dry-run cuando el chequeo de UID falla.

#### Scenario: Repair invocado como www

- **WHEN** `storage:repair-ownership` se ejecuta con UID 1000 (www)
- **THEN** SHALL abortar con mensaje "Repair requiere UID 0. Ejecute con sudo."
- **AND** SHALL NO crear tablas snapshot ni ejecutar chown ni chmod

#### Scenario: Repair invocado con UID 0

- **WHEN** `storage:repair-ownership` se ejecuta con UID 0 (root)
- **THEN** SHALL proceder con el flujo de dry-run o `--apply` según corresponda

### Requirement: Repair opera solo sobre hallazgos del audit

`storage:repair-ownership` SHALL recibir los paths a reparar desde el reporte del propio audit (no desde argumentos libres del usuario), SHALL aceptar `--limit=N` para acotar la cantidad procesada por corrida, SHALL default `--dry-run` (que imprime los paths que se repararían sin ejecutar chown ni chmod), y SHALL exigir confirmación interactiva cuando `--apply` se invoca sin `--yes` para más de 5 paths.

#### Scenario: Dry-run imprime paths sin mutar

- **WHEN** `storage:repair-ownership --storage=55` se ejecuta con UID 0 y hay 8 hallazgos `untraversable`
- **THEN** SHALL imprimir los 8 paths con modo, owner y group actuales
- **AND** SHALL NO ejecutar chown ni chmod
- **AND** SHALL terminar con código de salida 0

#### Scenario: Apply con confirmación interactiva

- **WHEN** `storage:repair-ownership --storage=55 --apply --limit=10` se ejecuta con UID 0 y el audit reportó 8 hallazgos
- **THEN** SHALL preguntar interactivamente "Repair 8 paths? [y/N]"
- **AND** SHALL abortar limpio si la respuesta no es 'y' o 'yes'

#### Scenario: Apply con --yes

- **WHEN** `storage:repair-ownership --storage=55 --apply --yes` se ejecuta con UID 0
- **THEN** SHALL ejecutar chown -R www:storage-admins y chmod -R u+rwX,g+rwX sobre los paths del hallazgo
- **AND** SHALL respetar `--limit` (no procesar más allá del límite)

### Requirement: Snapshot pre-cambio para rollback

`storage:repair-ownership` SHALL crear, antes de la primera mutación de una corrida `--apply`, una tabla snapshot `files_ownership_pre_repair_<YYYYMMDD_HHMMSS>` con copia exacta de las filas de `files` que viven bajo los paths a reparar (selección por `storage_provider_id` y `path LIKE '<path>/%'`). La tabla SHALL persistir después de la corrida para que el operador pueda hacer rollback granular vía SQL. SHALL NO droppear tablas snapshot de corridas previas.

#### Scenario: Repair crea snapshot

- **WHEN** `storage:repair-ownership --storage=55 --apply --yes` procesa 8 paths
- **THEN** SHALL existir una tabla `files_ownership_pre_repair_<ts>` con la copia exacta de las filas afectadas
- **AND** SHALL loguearse el nombre de la tabla y el conteo de filas copiadas

#### Scenario: Dos repairs consecutivos

- **WHEN** `storage:repair-ownership --storage=55 --apply --yes` se ejecuta dos veces seguidas
- **THEN** SHALL existir una tabla snapshot por cada corrida
- **AND** SHALL NO droppear la tabla de la corrida anterior

### Requirement: Repair respeta denylist

`storage:repair-ownership` SHALL filtrar los paths reportados por el audit contra la denylist (`System Volume Information`, `$RECYCLE.BIN`, `.Recycle_bin`, paths que empiezan con `.`) y SHALL abortar con error si tras el filtro quedan 0 paths a procesar. SHALL NO permitir override de la denylist por flag.

#### Scenario: Todos los hallazgos caen en denylist

- **WHEN** `storage:repair-ownership --apply --yes` se ejecuta y los únicos hallazgos `untraversable` son `System Volume Information` y `.Recycle_bin`
- **THEN** SHALL abortar con mensaje "Todos los hallazgos cayeron en denylist. Abort."
- **AND** SHALL NO crear tabla snapshot ni ejecutar mutaciones

### Requirement: Auditoría horaria

El schedule SHALL registrar `storage:audit-ownership` con cadencia horaria y `->withoutOverlapping(60)` para evitar corras concurrentes. SHALL NO ejecutar `--apply`. SHALL NO enviar mail automáticamente (la emisión de mail queda bajo el sistema existente que el operador ya configura).

#### Scenario: Schedule dispara audit

- **WHEN** el cron scheduler llega al minuto de disparo del `storage:audit-ownership`
- **THEN** SHALL ejecutar el comando en proceso independiente
- **AND** SHALL omitir el tick si el lock está tomado