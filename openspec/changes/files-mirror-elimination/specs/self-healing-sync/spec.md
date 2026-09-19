## MODIFIED Requirements

### Requirement: Sync migra archivos con storage_provider_id incorrecto al sub-storage correcto

Cuando `StorageSyncService::doSyncFolder()` procesa un file row existente y su `path` absoluto (`parent.base_path + '/' + file.path`) cae dentro del `base_path` de un sub-storage más específico, SHALL migrar el row: `UPDATE files SET storage_provider_id = <sub_storage_id>, parent_id = <folder_row_en_sub>` (o NULL si el archivo queda en la raíz del sub).

Esta migración SHALL ser el **único** mecanismo de reubicación cross-storage, y el sync SHALL NO crear ni enlazar filas espejo para representar el archivo en el storage padre.

#### Scenario: File row existente en parent con path bajo sub

- **WHEN** existe `f` con `storage_provider_id = P` y su `path` absoluto es descendiente de `S.base_path` para algún sub-storage `S` (S.id != P)
- **AND** `doSyncFolder` se ejecuta sobre el folder que contiene `f`
- **THEN** SHALL migrar `f` a `S`: `storage_provider_id = S.id`, `parent_id` apuntando al folder row equivalente en `S`
- **AND** SHALL preservar el `path` completo (no se trunca)
- **AND** SHALL preservar `file_id` (las FKs de `transcriptions`, `shares`, `media_edit_jobs` siguen siendo válidas)
- **AND** SHALL loggear `storage_sync.file_migrated_to_substorage` con `file_id`, `from_storage_id`, `to_storage_id`, `path`
- **AND** SHALL NO crear una fila espejo en `P`
- **AND** SHALL NO escribir `files.canonical_folder_id`

#### Scenario: Sync sobre una carpeta del padre no materializa espejos

- **WHEN** el sync indexa una carpeta nueva que existe físicamente dentro de un sub-storage, desde un scan disparado en el storage padre
- **THEN** SHALL existir una sola fila en `files` para esa carpeta, en el sub-storage
- **AND** NO SHALL existir una fila adicional en el storage padre con `path` relativo al padre
- **AND** la carpeta SHALL seguir siendo navegable desde el padre por resolución de path físico

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
