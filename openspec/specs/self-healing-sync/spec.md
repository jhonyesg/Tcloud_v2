## Purpose

El sistema de sincronización detecta y migra automáticamente archivos cuyo `storage_provider_id` no coincide con el sub-storage que físicamente posee su path. Auto-repair diario limpia cualquier drift residual. Defense in depth en `checkFilePermission` permite que descargas funcionen incluso durante la ventana entre la detección y la migración.

## Requirements

### Requirement: Sync migra archivos con storage_provider_id incorrecto al sub-storage correcto

Cuando `StorageSyncService::doSyncFolder()` procesa un file row existente y su `path` absoluto (`parent.base_path + '/' + file.path`) cae dentro del `base_path` de un sub-storage más específico, SHALL migrar el row: `UPDATE files SET storage_provider_id = <sub_storage_id>, parent_id = <folder_row_en_sub>` (o NULL si el archivo queda en la raíz del sub).

#### Scenario: File row existente en parent con path bajo sub

- **WHEN** existe `f` con `storage_provider_id = P` y su `path` absoluto es descendiente de `S.base_path` para algún sub-storage `S` (S.id != P)
- **AND** `doSyncFolder` se ejecuta sobre el folder que contiene `f`
- **THEN** SHALL migrar `f` a `S`: `storage_provider_id = S.id`, `parent_id` apuntando al folder row equivalente en `S`
- **AND** SHALL preservar el `path` completo (no se trunca)
- **AND** SHALL preservar `file_id` (las FKs de `transcriptions`, `shares`, `media_edit_jobs` siguen siendo válidas)
- **AND** SHALL loggear `storage_sync.file_migrated_to_substorage` con `file_id`, `from_storage_id`, `to_storage_id`, `path`

#### Scenario: Sub-storage ya tiene file con mismo path

- **WHEN** `f` debería migrarse a `S` pero `S` ya tiene un file con `path=f.path` (el cron del sub escaneó el mismo archivo independientemente)
- **THEN** SHALL re-apuntar las FKs (`transcriptions.file_id`, `shares.file_id`, `media_edit_jobs.source_file_id`) del leak al file existente en `S`
- **AND** SHALL borrar la fila del leak
- **AND** SHALL NO perder ninguna transcripción/share/media_edit_job

#### Scenario: Folder trashed en el path del sub

- **WHEN** al migrar `f`, la cadena de folders a crear en `S` tiene un folder con `is_trashed=true`
- **THEN** SHALL abortar la migración de `f` (queda con `storage_provider_id=P` original)
- **AND** SHALL loggear `storage_sync.self_heal_trashed_collision` con `file_id` y `path`
- **AND** SHALL continuar con el siguiente file (no aborta todo el sync)

#### Scenario: Sync sigue funcionando normalmente para files correctos

- **WHEN** `f` tiene `storage_provider_id` ya correcto (su path absoluto coincide con su storage)
- **THEN** SHALL NO modificarlo (no UPDATE de storage_provider_id ni parent_id)
- **AND** SHALL seguir actualizando size/mtime/last_verified_at como antes

### Requirement: Auto-repair de orphan subtree corre diario

`files:repair-orphan-subtree` SHALL ejecutarse vía cron a las 03:30 Bogota con `withoutOverlapping(60)` lock distribuido, para reparar archivos con `parent_id IS NULL` cuyo path cae bajo un sub-storage.

#### Scenario: Cron schedule registrado

- **WHEN** el operador consulta `php artisan schedule:list`
- **THEN** SHALL mostrar el schedule `files:repair-orphan-subtree` con `dailyAt('03:30')` y `withoutOverlapping(60)`
- **AND** SHALL mostrar el schedule `files:repair-delegation-leak --apply --storage=5` con `dailyAt('03:45')` y `withoutOverlapping(60)`

#### Scenario: Auto-repair respeta lock distribuido

- **WHEN** el cron intenta correr `files:repair-orphan-subtree` mientras una instancia manual del mismo comando está activa (lock tomado)
- **THEN** SHALL abortar con mensaje claro ("Otra instancia esta corriendo")
- **AND** SHALL NO corromper datos ni tomar locks adicionales

### Requirement: detect-duplicate-paths reporta delegation leaks

`storages:detect-duplicate-paths --include-delegation-leaks` SHALL listar, además de los pares de storages duplicados, el conteo de files en cada (origin_storage, target_sub_storage) donde `origin.base_path + '/' + file.path` cae bajo `target.base_path`.

#### Scenario: Reporte incluye delegation leaks

- **WHEN** operador ejecuta `storages:detect-duplicate-paths --include-delegation-leaks`
- **THEN** SHALL listar los pares de storages duplicados (comportamiento existente)
- **AND** SHALL agregar una seccion "Delegation leaks" con tabla: `origin_storage_id | target_sub_storage_id | leaked_files_count`
- **AND** SHALL NO mutar ningún dato

### Requirement: download devuelve 200 OK si el usuario tiene acceso al ANCESTRO del file row

Cuando `FileController::download` llama `checkFilePermission`, si `user.hasStoragePermission(file.storage_provider_id)` retorna false pero el `parent_storage_id` chain de `file.storage_provider_id` incluye un storage donde el usuario SÍ tiene acceso, SHALL retornar true (defense in depth).

#### Scenario: File row con storage_provider_id incorrecto, user con acceso al ancestor

- **WHEN** existe `f` con `storage_provider_id = 5` (00 Discos) y `path` bajo storage 6 (sub-storage)
- **AND** user "Punto" tiene acceso a storage 6 pero NO a storage 5
- **AND** user hace click en `f` para descargar
- **THEN** SHALL retornar 200 OK con el archivo (no Forbidden)
- **AND** SHALL loggear `file_controller.ancestor_permission_granted` con `file_id`, `file_storage_id`, `granted_via_storage_id`

#### Scenario: User sin acceso a ningún ancestor

- **WHEN** user "Punto" NO tiene acceso a `file.storage_provider_id` ni a ningún ancestor
- **THEN** SHALL retornar 403 Forbidden (comportamiento existente preservado)

#### Scenario: Admin bypass

- **WHEN** user es admin (cualquier role de admin)
- **THEN** SHALL retornar true sin chequear storage (comportamiento existente preservado)
