# files-canonical-owner Specification

## Purpose
TBD - created by archiving change files-canonical-owner-by-storage. Update Purpose after archive.

## Requirements

### Requirement: Cada storage tiene un owner canónico único y determinístico

El sistema SHALL calcular `canonicalOwnerId(storage_id)` con las siguientes reglas, en orden:

1. Si `is_personal = true`: el `user_id` con `permissions='full'` y **menor `user_id`** entre los `user_storages` del storage.
2. Si `is_personal = false`: el `user_id` con `permissions='full'` y **menor `user_storages.id`** (insertion order) entre los `user_storages` del storage.
3. Si ninguno tiene `permissions='full'` propio, subir por la cadena `parent_storage_id` aplicando la misma regla 2.
4. Si la cadena completa no tiene `permissions='full'`, retornar `NULL`.
5. Toda asignación nueva de `files.owner_id` SHALL fallar con error explícito si el helper retorna `NULL`.

#### Scenario: Storage compartido con permissions=full propio
- **WHEN** se consulta el owner canónico de un storage compartido (`is_personal=false`) con al menos un `user_storages.permissions='full'`
- **THEN** el resultado es el `user_id` del registro con menor `user_storages.id` entre los que tienen `permissions='full'`
- **AND** el resultado es idéntico en 100 invocaciones consecutivas (determinístico)

#### Scenario: Storage personal con un solo user full
- **WHEN** se consulta el owner canónico de un storage con `is_personal=true` donde solo el usuario X tiene `permissions='full'`
- **THEN** el resultado es X

#### Scenario: Storage personal con admin y personal user ambos full (edge case)
- **WHEN** un storage con `is_personal=true` tiene dos `user_storages` con `permissions='full'`: admin (user_id=1, user_storages.id=10) y usuario personal (user_id=5, user_storages.id=20)
- **THEN** el resultado es el usuario personal (user_id=5, menor `user_id`)
- **AND** el admin NO se convierte en owner canónico del storage personal

#### Scenario: Storage sin full propio pero con padre que sí tiene
- **WHEN** se consulta el owner canónico de un storage hijo sin `full` propio pero cuyo `parent_storage_id` apunta a un storage con `full`
- **THEN** el resultado es el `user_id` canónico del padre (propagado por la cadena)

#### Scenario: Cadena completa sin permissions=full
- **WHEN** se consulta el owner canónico de un storage cuya cadena completa de padres no tiene `permissions='full'`
- **THEN** el resultado es NULL
- **AND** cualquier intento de crear un `File` en ese storage SHALL fallar con error HTTP 500 explícito mencionando "storage X sin owner canónico"

#### Scenario: Guardia anti-ciclo en parent chain
- **WHEN** la cadena `parent_storage_id` contiene un ciclo (A → B → A)
- **THEN** el helper retorna NULL en lugar de loop infinito

### Requirement: Las vías de creación de archivos asignan owner canónico

El sistema SHALL asignar `files.owner_id = StorageProvider::canonicalOwnerId($storage_provider_id)` en TODAS las vías de creación: `StorageSyncService::syncFolder`, `StorageSyncService::syncRootFolder`, `FileController::store`, `FileController::upload`, `FileController::copy`, `FileController::copyFolderRecursively`, `PublicShareController::upload`, `PublicShareController::createFolder`, `RepairDelegationLeakCommand`, `RepairOrphanSubtreeCommand`. Ninguna SHALL usar `userStorages()->first()` sin `orderBy`. Para storages donde el helper retorna NULL, SHALL lanzar excepción.

#### Scenario: Sync crea file con owner canónico
- **WHEN** el sync procesa un path en un storage compartido
- **THEN** el row se crea con `owner_id = canonicalOwnerId(storage_id)`
- **AND** el `owner_id` es el mismo independientemente del usuario que ejecuta el sync

#### Scenario: Upload de usuario read en storage compartido
- **WHEN** un usuario con `permissions='read'` (no `full`) sube un archivo a un storage compartido
- **THEN** el row se crea con `owner_id` del admin canónico, NO del usuario que subió
- **AND** el usuario puede ver su archivo via storage permissions (no vía owner_id)

#### Scenario: Upload via public share
- **WHEN** alguien sube un archivo via share público a un folder del storage X
- **THEN** el nuevo file tiene `owner_id = canonicalOwnerId(storage_id)` independientemente del `owner_id` del folder target

#### Scenario: Sync en storage sin canónico resuelto
- **WHEN** el sync intenta crear un file en un storage cuya cadena completa no tiene `full`
- **THEN** la creación falla con excepción `RuntimeException("storage X sin owner canónico, asigne permissions=full a un user_storages antes de sincronizar")`
- **AND** el sync reporta el error al log

### Requirement: Papelera filtra por storage access, no por owner_id

El sistema SHALL listar y operar la papelera basándose en los `storage_provider_id` a los que el usuario tiene acceso via `user_storages`, NO en `files.owner_id`. Cada usuario ve la papelera de los storages donde tiene cualquier nivel de permiso. El admin ve toda la papelera.

#### Scenario: Usuario regular ve papelera de storages con acceso
- **WHEN** un usuario con `permissions='read'` en storages 5, 47, 134 consulta `/papelera`
- **THEN** ve los archivos trashados en storage 5 + 47 + 134
- **AND** NO ve archivos trashados en storages donde no tiene acceso (ej. storage 170 personal de jsuarez)

#### Scenario: Admin ve toda la papelera
- **WHEN** un usuario con `role='admin'` consulta `/papelera`
- **THEN** ve TODOS los archivos trashados del sistema

#### Scenario: Restaurar archivo de papelera
- **WHEN** un usuario con acceso al storage trashó un archivo y lo quiere restaurar
- **THEN** la papelera muestra ese archivo (porque el usuario tiene acceso al storage)
- **AND** el restore funciona independientemente de quién sea el `owner_id` del archivo

#### Scenario: Usuario sin acceso a un storage no ve su papelera
- **WHEN** un usuario sin `user_storages` en el storage X consulta `/papelera`
- **THEN** NO ve archivos trashados del storage X

### Requirement: Rename y delete usan storage permission, no owner_id

El sistema SHALL permitir rename y delete de un archivo cuando `isAdmin() OR $user->hasStoragePermission($file->storage_provider_id, 'full')`. NO SHALL requerir `owner_id === $user->id`.

#### Scenario: Usuario con full puede renombrar archivo de storage compartido
- **WHEN** un usuario con `permissions='full'` en el storage X quiere renombrar un archivo del storage X
- **THEN** el rename funciona aunque `owner_id` del archivo sea el admin canónico del storage

#### Scenario: Usuario con read no puede renombrar
- **WHEN** un usuario con `permissions='read'` (no `full`) en el storage X quiere renombrar un archivo
- **THEN** recibe 403 "Full permission required"

#### Scenario: Admin puede renombrar cualquier archivo
- **WHEN** un admin quiere renombrar cualquier archivo
- **THEN** el rename funciona independientemente del storage

### Requirement: Migración canónica reasigna files.owner_id

El sistema SHALL proveer una migración reversible que, para cada storage compartido (`is_personal = false`), reasigna `files.owner_id` al `canonicalOwnerId` cuando difiere. La migración SHALL preservar todos los demás campos del row, SHALL ejecutarse dentro de una transacción con `statement_timeout=0`, `lock_timeout=0`, y `session_replication_role = replica`. SHALL emitir conteo antes/después por storage. La migración NO debe tocar files de storages personales ni files trashados.

#### Scenario: Storage con un solo owner_id actual == canónico
- **WHEN** se ejecuta la migración sobre un storage donde todos los files ya tienen `owner_id = canonicalOwnerId`
- **THEN** la migración deja 0 rows modificadas para ese storage

#### Scenario: Storage con múltiples owner_id actuales
- **WHEN** se ejecuta la migración sobre un storage con files asignados a N usuarios diferentes
- **THEN** después de la migración todos los files del storage tienen `owner_id = canonicalOwnerId`
- **AND** los shares, transcriptions y demás referencias a esos files siguen intactas

#### Scenario: Files trashados excluidos
- **WHEN** la migración procesa un storage con 100k files trashados
- **THEN** esos files NO son modificados (la papelera preserva el `owner_id` original)

#### Scenario: Reversibilidad
- **WHEN** se ejecuta `migrate:rollback`
- **THEN** los `files.owner_id` vuelven a su valor anterior desde `files_owner_canonical_audit`

### Requirement: Harness de regresión verifica invariantes críticas

El sistema SHALL incluir `tests/harness_files_canonical_owner.php` con 7 secciones y ≥30 aserciones que verifican: determinismo del helper (100 invocaciones), cobertura de grep (0 callsites con `userStorages()->first()` sin orderBy), unicidad de (storage_id, path), comportamiento de papelera post-fix, comportamiento de rename/delete, edge case de personal storage con admin dual-full, smoke test de las 4 vías de creación.

#### Scenario: Harness pasa en estado correcto
- **WHEN** se ejecuta `cd app && php tests/harness_files_canonical_owner.php` después del fix
- **THEN** retorna exit 0 y reporta PASS en todas las secciones

#### Scenario: Harness detecta regresión
- **WHEN** un dev introduce un nuevo callsite que usa `userStorages()->first()` sin `ORDER BY`
- **THEN** la sección de grep del harness falla y bloquea el merge
