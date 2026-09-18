## Why

Las dos columnas `merged_into_id` existentes — una en `storage_providers`, otra en `files` — **miden cosas distintas con el mismo nombre**: la primera marca duplicados de nodos de storage (operación `storages:merge-duplicates`); la segunda marca folders espejo (operación `files:repair-folder-mirrors`). Cualquier agente o humano que analice SQL cruzado razona por analogía y confunde semántica. Adicionalmente, `storage_providers` arrastra `type` y `kind` con valores parcialmente distintos (`type` ∈ {local, s3}; `kind` ∈ {local, external}; 1 fila diverge), creando un riesgo silencioso en cada nuevo endpoint. La auditoría de nombres vivos en el repo (grep) tocó **22 referencias a `files.merged_into_id`** y **~30 referencias a `storage->type`** repartidas en models, services, commands, controllers, tests y harnesses. Un rename coordinado elimina la confusión y deja la frontera semántica explícita en el esquema.

## What Changes

- **PR 1 (renames + cleaning, reversible)**:
  - `storage_providers.merged_into_id` → `duplicate_of_storage_id` (FK self, `ON DELETE SET NULL`).
  - `files.merged_into_id` → `canonical_folder_id` (FK self, `ON DELETE SET NULL`).
  - `storage_providers.type` se **mantiene** en este PR (un storage con `type='s3'` todavía existe); se depreca con `@deprecated` en el modelo y se documenta el contrato `kind` como el discriminador canónico. La eliminación total queda para PR 2.
  - Renombrar constantes, helpers, getters (`File::isMirror()` → `File::isFolderMirror()`), servicios (`FilePhysicalIdentity::link/unlink`) y los triggers de `file_mirror_audit_log` para que el vocabulario coincida.
  - Actualizar todos los call sites: 4 controllers, 3 commands, 2 services, 2 modelos, 2 harnesses de regresión.
  - Cada cambio se entrega bajo una **migration de doble vía**: copia de datos en TX + `ALTER TABLE RENAME COLUMN` en una sola operación, reversible con `down()`.
  - Harness nuevo `tests/harness_merged_into_rename_compat.php` que verifica que ambos nombres viejos y nuevos resuelven a los mismos IDs durante el período de convivencia.

- **NO-breaking** en runtime: la app migrada solo usa los nombres nuevos. Los nombres viejos se eliminan de la BD en la misma migration. No hay ventana de compatibilidad porque no hay clientes externos leyendo estas columnas.

## Non-goals

- **NO** se renombra `files.base_path_snapshot` en este PR (es cosmético y se discute en una propuesta separada).
- **NO** se introduce UNIQUE constraint nuevo ni se cambian políticas de CASCADE/SET NULL.
- **NO** se elimina `storage_providers.type` (aún alimenta paths en production con `type='s3'`).
- **NO** se mueven tablas ni se cambian enums.
- **NO** se modifican vistas Blade ni contratos HTTP públicos (los cambios son internos).

## Capabilities

### New Capabilities

- `schema-clarity-rename-policy`: documenta el contrato de renombrado (semántica de cada nueva columna, criterios de cuándo añadir sinónimos vs cuándo migrar de raíz, cómo auditar nombres nuevos antes de fusionar).

### Modified Capabilities

- `files-physical-folder-identity`: el campo `merged_into_id` (NOMBRE) usado por esta capability ahora se llama `canonical_folder_id`. La SEMÁNTICA no cambia (sigue marcando filas folder que son espejo de una canónica en otro storage). Migración no-breaking.
- `storage-physical-identity`: el campo `merged_into_id` (NOMBRE) usado por esta capability ahora se llama `duplicate_of_storage_id`. La SEMÁNTICA no cambia (sigue marcando storages que son duplicados físicos de otro). Migración no-breaking.

## Impact

**Migración requerida**: SÍ (`up()` + `down()` reversibles bajo TX).

**Archivos tocados** (estimación grep-based):
- 1 migration nueva: `app/database/migrations/2026_09_XX_rename_merged_into_*.php` (idempotente, transactional).
- `app/app/Models/File.php`: renombrar `merged_into_id` fillable, helper `isMirror()`, `canonical()`.
- `app/app/Models/StorageProvider.php`: renombrar fillable y `isMerged()` → `isDuplicate()`.
- 3 commands: `MergeDuplicatesCommand`, `RepairFolderMirrorsCommand`, `ShowCanonicalizationImpactCommand`.
- 2 services: `FilePhysicalIdentity`, `FolderListingService`.
- 2 controllers: `PublicShareController::destroy` (log payload incluye `canonical_file_id`), `StorageProviderController` (`if-duplicate` block).
- 2 harnesses de regresión: `harness_files_folder_mirror.php`, `harness_storage_sync_is_file_linked.php` (cambian nombre de columna consultada).
- `app/database/migrations/2026_09_17_120100_create_file_mirror_audit_log.php`: el CHECK de `action` enum (`link_mirror`/`repoint_share`) documenta la semántica y queda intacto.

**Reversibilidad**: `migrate:rollback` deja `merged_into_id` como estaba; los call sites en código deben re-aplicarse con el nombre viejo (down del código incluido en la migration para evitar drift).

**Riesgo**: medio por blast radius (~35 archivos). Mitigado por:
1. Harness con aserciones sobre nombres viejos Y nuevos durante 1 ciclo.
2. PR atómico (no se puede mergear a medias).
3. No requiere orden de deploy especial (no es schema break para queries de la app).
