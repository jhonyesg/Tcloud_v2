## Why

El módulo Mis Archivos funcionó de forma estable y predecible entre el 27-jul (commit `a15e5f3`) y el 19-ago-2026: cada storage tenía su listado independiente, cada usuario veía los storages asignados, los shares funcionaban, y el sync corría sin sobresaltos. Desde el 5 de septiembre se agregaron 11 columnas nuevas a `files` (papelera, availability, mirrors cross-storage, owner canónico), 3 tablas auxiliares y 690 líneas de lógica en `StorageSyncService` para resolver listings cross-storage. El resultado en producción (verificado 2026-09-19): **1.455.875 mirror rows, 94% rotas, descargas con HTTP 400, listados vacíos** (`openspec/changes/files-mirror-elimination/proposal.md`). El operador perdió la confianza en el módulo y quiere volver al modelo simple de agosto donde "yo tenía storage, esos storage se asignaban a usuarios, y con ellos los usuarios podían verlo sin problemas". Restauramos ese modelo como baseline, conservando las **defensas probadas** del fix del 27-jul (FileRegistry idempotente, PruneGuard, MountGuard, files:dedupe) y la invariante **anti-duplicidad** (`UNIQUE (storage_provider_id, path)`).

## What Changes

- **Revertir los 11 archivos del módulo** al estado de `bb71b9c` (20-ago-2026): `FileController`, `PublicShareController`, `ShareController`, `File.php`, `StorageProvider.php`, `StorageSyncService.php`, `FileRegistry.php`, `FileScannerService.php`, `DedupeFiles.php`, `SyncStorage.php`, vistas `files/index.blade.php` y `files/preview.blade.php`, test `StorageSyncPruneTest.php`.
- **DROP COLUMN** en `files`: `canonical_folder_id`, `merged_into_id`, `merged_at`, `merged_reason`, `base_path_snapshot`, `is_trashed`, `deleted_at`, `availability_state`, `last_verified_at`, `missing_since_at`, `original_parent_id`. **BREAKING**.
- **DROP TABLE**: `file_mirror_audit_log`, `files_owner_canonical_audit`, `files_prune_batches`. **BREAKING**.
- **DELETE las 1.455.875 mirror rows** tras repunte de FKs en `transcriptions.file_id` (78.547) y `shares.file_id` (33) — comando `files:repair-mirror-targets --apply` antes del DROP.
- **Retirar clases**: `App\Services\FilePhysicalIdentity`, `App\Services\FolderListingService`, `App\Services\StorageHierarchyService`, `App\Services\Ia\StorageFunnelService`. **BREAKING** para cualquier callsite externo.
- **Ajustar el cron de sincronización** (`routes/console.php` + supervisord `tcloud-storage-sync-*`) para que use el `StorageSyncService` simplificado de agosto: `syncFolder()`, `syncFolderWithReport()`, `fullSync()` sin `resolveListingTargets`, `ensureSubstorageFolderChain`, `selfHealDelegationLeak`. Mantiene FileRegistry, PruneGuard, MountGuard.
- **Conservar**: `UNIQUE (storage_provider_id, path)`, FileRegistry idempotente, PruneGuard, MountGuard, comando `files:dedupe`, `SyncStorage` command, vista `files/index.blade.php` con su storage switcher per-storage.
- **Quitar de AGENTS.md** las secciones marcadas como obsoletas por la reversión: "Permission checks strict per storage_id (post fix-self-healing-permission-leak)", "Folder identity canónica para shares (files-physical-folder-identity + share-folder-canonical-wiring)", "Cross-storage access verification en shares públicos".

## Capabilities

### New Capabilities

- `mis-archivos-stable-august-baseline`: el módulo Mis Archivos con el modelo simple de agosto — listado per-storage sin cross-storage, sin mirror rows, sin trash per-file, sin availability tracking. Una identidad física = una fila, simple y estable.

### Modified Capabilities

- `file-permission-checks`: se simplifica el helper canónico (`StorageProvider::canonicalOwnerId` se retira); el check vuelve a `hasStoragePermission(file.storage_provider_id, perm)` sin el `original_parent_id` self-healing.
- `files-search`: el listado y búsqueda vuelven a `WHERE storage_provider_id IN (user_storage_ids)` sin `resolveListingTargets` cross-storage.
- `file-upload-ux`: el upload vuelve a crear la fila directamente en el storage del usuario, sin delegar a sub-storage.
- `share-file-download`: el share se crea sobre el `file_id` recibido (sin canonicalización previa vía `FilePhysicalIdentity`); el `isDescendantOf` vuelve a walk de `parent_id` sin fallback `physicalPathNormalized`. **BREAKING** para shares sobre folders de sub-storages que viven en el padre.
- `storage-health-and-reconcile`: el comando `storages:health-check` retira el chequeo de `availability_state` (la columna no existe).

### Removed Capabilities

- `files-canonical-owner` — la columna `files.canonical_folder_id` y el helper `StorageProvider::canonicalOwnerId` se retiran.
- `files-mis-archivos-sub-storage-listing` — `resolveListingTargets` se retira.
- `share-folder-identity-canonical` — la canonicalización via `FilePhysicalIdentity::canonicalFor` se retira.
- `storage-physical-identity` — `base_path_snapshot`, `physicalPathNormalized()` se retiran.
- `files-folder-context-staleness-guard` — el guard contra self-healing delegation leak se retira.
- `files-storages-banner-ux` — el banner "X archivos sin storage asignado" se retira (no aplica al modelo simple).

## Impact

**Código revertido**: 15 archivos del módulo (5.4 MB en `/tmp/kilo/mis-archivos-agosto/` listos para copiar).
**Migración nueva**: `app/database/migrations/2026_09_20_*.php` con DOWN que restaura `bb71b9c` (13 columnas) y DROP de las 11 columnas nuevas + 3 tablas. Backup pre-drop en `file_mirror_audit_log_pre_drop_<ts>.sql.gz`.
**Comando nuevo**: `files:repair-mirror-targets --apply` (repunte de FKs antes del DROP).
**Riesgos**: (R1) las 78.547 transcripciones y 33 shares que apuntan a mirror rows deben ser repuntadas ANTES del DROP — sin esto, CASCADE borra el share/transcripción. (R2) las apps externas (cliente web, mobile) que llamen `GET /files?parent_id=X&storage_id=Y` con un parent_id de un folder mirror dejarán de verlo — mitigación: avisar al cliente + comando de reparación ejecutado antes del deploy. (R3) rollback requiere re-aplicar 11 migraciones (5 min de downtime planificado).
**Rutas afectadas**: `GET/POST /files/*`, `GET/POST /shares/*`, `GET /public/shares/*` — todas las rutas de Mis Archivos y shares.
**APIs externas**: ninguna API pública documentada cambia de contrato, pero el shape de algunos JSON responses se simplifica (sin `canonical_folder_id`, sin `availability_state`).
**Cobertura de tests**: `tests/Feature/StorageSyncPruneTest.php` (agosto) + harness `tests/harness_files_physical_identity.php` se archiva; nuevos harnesses a definir.
