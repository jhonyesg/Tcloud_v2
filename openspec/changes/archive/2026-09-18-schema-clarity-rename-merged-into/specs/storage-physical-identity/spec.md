## ADDED Requirements

### Requirement: Columna renamed para claridad semántica

The system SHALL rename `storage_providers.merged_into_id` to `storage_providers.duplicate_of_storage_id` so that the column name unambiguously expresses its domain (a storage node that duplicates another storage node physically).

#### Scenario: Columna renommée en BD
- **WHEN** se ejecuta la migration de rename
- **THEN** la columna SHALL llamarse `duplicate_of_storage_id`
- **AND** SHALL conservar el FK constraint `storage_providers_merged_into_id_fkey` (cambia el nombre del constraint si PG lo permite, sino se renombra explícitamente)
- **AND** SHALL conservar el partial index `storage_providers_merged_into_id_idx` (renombrado a `storage_providers_duplicate_of_storage_id_idx`)
- **AND** SHALL conservar `ON DELETE SET NULL` semantics

#### Scenario: Helper renombrado en modelo
- **WHEN** el código revisa `StorageProvider::isDuplicate()`
- **THEN** SHALL retornar true si y solo si `duplicate_of_storage_id IS NOT NULL`
- **AND** SHALL NO existir el método `isMerged()` (eliminado)

#### Scenario: Queries SQL actualizadas
- **WHEN** cualquier consulta referencia `merged_into_id` en `storage_providers`
- **THEN** SHALL usar `duplicate_of_storage_id`
- **AND** SHALL NO quedar referencias viejas (verificado por `rg "storage_providers.*merged_into_id|->merged_into_id" app/` retornando 0 matches)

## MODIFIED Requirements

### Requirement: Cada ubicación física tiene UN storage canónico

`storage_providers` SHALL garantizar que para cada `(kind, physical_path_normalized)` existe a lo sumo UN row con `duplicate_of_storage_id IS NULL`. `physical_path_normalized` se define como `lower(rtrim(base_path, '/'))` para `base_path IS NOT NULL AND base_path <> ''`, y NULL en otro caso.

#### Scenario: UNIQUE constraint rechaza nuevo duplicado
- **WHEN** el admin intenta crear un storage con `base_path='/Tcloud/Bogota/LA_FM'` cuando ya existe storage 61 con ese path
- **THEN** SHALL fallar con HTTP 409 (application layer) Y con `SQLSTATE[23505]` (database layer)
- **AND** SHALL sugerir al operador: "ya existe storage 61 '01 Radio FM Bogota' en ese path; use ese en su lugar"

#### Scenario: Storage sin base_path no participa del constraint
- **WHEN** un storage tiene `base_path IS NULL OR base_path = ''`
- **THEN** SHALL tener `physical_path_normalized = NULL`
- **AND** SHALL NO participar del UNIQUE constraint

### Requirement: Helper findByNormalizedPath

`StorageProvider::findByNormalizedPath(string $normalized, string $kind): ?StorageProvider` SHALL retornar el storage row canónico para `(kind, normalized)`. Si hay múltiples candidatos, SHALL retornar el canonical según el algoritmo de selección.

#### Scenario: Path normalizado retorna canonical
- **WHEN** se llama `findByNormalizedPath('/Tcloud/.../Bogota/', 'local')`
- **THEN** SHALL retornar storage 47 ("01 Emisoras 01")
- **AND** SHALL excluir storages con `duplicate_of_storage_id IS NOT NULL`

#### Scenario: Path no existe retorna null
- **WHEN** se llama `findByNormalizedPath('/Tcloud/no/existe/', 'local')`
- **THEN** SHALL retornar NULL

### Requirement: Detección de paths duplicados

`storages:detect-duplicate-paths [--dry-run]` SHALL listar todos los pares de storages con el mismo `physical_path_normalized` y `duplicate_of_storage_id IS NULL`. Para cada par SHALL indicar `canonical_id` (algoritmo de selección), `duplicate_id`, conteo de files/transcriptions/shares/watermarks en cada uno, y `merge_recommended=true`.

#### Scenario: Reporte identifica 8 pares
- **WHEN** se ejecuta `storages:detect-duplicate-paths --dry-run` en el estado actual
- **THEN** SHALL listar 8 pares
- **AND** SHALL mostrar conteos exactos para `files`, `transcriptions`, `shares`, `keyword_scan_watermarks`
- **AND** SHALL escribir a log `storages.duplicates_detected` con el total

#### Scenario: Detección programada corre diariamente
- **WHEN** el cron `storages:detect-duplicate-paths` corre (sin `--dry-run` es seguro porque no muta)
- **THEN** SHALL actualizar `SystemSetting('storage.duplicates_remaining')` con el conteo
- **AND** SHALL NO modificar ningún storage

### Requirement: Merge de storages duplicados es supervisado

`storages:merge-duplicates --canonical=<id> --duplicate=<id> [--apply] [--yes]` SHALL consolidar un par de storages duplicados en el canonical. Sin `--apply` SHALL ejecutar el merge en dry-run: leer estado, proyectar cambios, NO mutar. Con `--apply` SHALL ejecutar el merge completo en transacción, escribir audit log, y exit.

#### Scenario: Dry-run proyecta el merge sin mutar
- **WHEN** operador ejecuta `storages:merge-duplicates --canonical=132 --duplicate=142`
- **THEN** SHALL mostrar: archivos a re-forkear, archivos duplicados (path collision) a borrar, FKs de transcripciones/shares/media_edit_jobs a re-apuntar, watermarks a sum-mergear, conteo de usuarios que perderían acceso
- **AND** SHALL NO modificar ninguna fila
- **AND** SHALL NO escribir audit log

#### Scenario: Apply ejecuta el merge completo
- **WHEN** operador ejecuta `storages:merge-duplicates --canonical=132 --duplicate=142 --apply`
- **THEN** SHALL requerir confirmación interactiva ("escribe 'merge' para confirmar") salvo que `--yes` esté presente
- **AND** SHALL ejecutar todo el merge en una transacción
- **AND** SHALL escribir fila en `storage_merges` (audit log) con: canonical_id, duplicate_id, files_moved, files_deduped, fk_tables_affected, executed_by_user_id, executed_at
- **AND** SHALL marcar duplicate: `duplicate_of_storage_id=132, merged_at=now(), merged_reason='manual merge via storages:merge-duplicates'`, `enabled=false`, `base_path=NULL`

#### Scenario: Merge preserva transcripciones por re-pointing
- **WHEN** canonical tiene archivo en path X pero duplicate también
- **THEN** SHALL re-apuntar `transcriptions.file_id`, `shares.file_id`, `media_edit_jobs.source_file_id` del duplicate al canonical
- **AND** SHALL borrar la fila duplicada en `files` (sin pérdida de FKs)
- **AND** SHALL preservar el historial de transcripciones (no se borra ninguna fila de `transcriptions`)

#### Scenario: Merge preserva keyword_scan_watermarks por sum-merge
- **WHEN** duplicate tiene watermark para `keyword_id=K, scanned_until=T1`
- **AND** canonical tiene watermark para `keyword_id=K, scanned_until=T2`
- **THEN** SHALL tomar MAX(T1, T2) como nuevo `scanned_until` del canonical
- **AND** SHALL sumar `candidates_total` y `hits_total`
- **AND** SHALL borrar la fila del duplicate
- **AND** SHALL escribir entrada en `watermark_audit_log` con la acción 'merge_storage'

#### Scenario: Merge preserva acceso de usuarios
- **WHEN** duplicate tiene `user_storages` para `user_id=U`
- **AND** canonical también tiene `user_storages` para `user_id=U`
- **THEN** SHALL borrar la fila del duplicate (UNIQUE constraint violation si re-pointing)
- **AND** SHALL NOT perder acceso del usuario (sigue teniendo acceso vía canonical)
- **WHEN** duplicate tiene `user_storages` para `user_id=U`
- **AND** canonical NO tiene acceso para `user_id=U`
- **THEN** SHALL re-apuntar `storage_provider_id=canonical.id` (preserva acceso)

### Requirement: Sync solo delega a canonical storages

`StorageSyncService::findMoreSpecificStorage()` SHALL excluir storages con `duplicate_of_storage_id IS NOT NULL` de los candidatos para delegación.

#### Scenario: Sync ignora storages mergeados
- **WHEN** storage 142 está mergeado (duplicate_of_storage_id=132) y storage 47 escanea un archivo bajo `/Tcloud/.../La_voz/`
- **THEN** `findMoreSpecificStorage()` SHALL retornar storage 132 (canonical)
- **AND** SHALL NO considerar storage 142 como candidato

### Requirement: Controller bloquea creación de duplicados con HTTP 409

`StorageProviderController::store()` y `update()` SHALL validar que el `base_path` normalizado no exista ya en otro storage activo (no mergeado). Si existe, SHALL retornar HTTP 409 con mensaje accionable.

#### Scenario: Admin intenta crear storage duplicado
- **WHEN** admin envía POST con `base_path='/Tcloud/.../Bogota/LA_FM'` cuando storage 61 ya existe
- **THEN** SHALL retornar HTTP 409
- **AND** SHALL incluir mensaje: "ya existe storage 61 '01 Radio FM Bogota' en ese path. Use ese en su lugar o cree un path distinto."

#### Scenario: Admin edita base_path de un storage mergeado
- **WHEN** storage 142 (duplicate_of_storage_id=132) tiene `enabled=false`
- **AND** admin intenta `PUT /admin/storages/142` con `base_path='/nuevo/path'`
- **THEN** SHALL permitir (los storages mergeados pueden re-habilitarse con un nuevo path)
- **AND** SHALL limpiar `duplicate_of_storage_id`, `merged_at`, `merged_reason` (un-merge)
