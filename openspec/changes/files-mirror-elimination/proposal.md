## Why

`files` materializa **1.455.875 mirror rows** (`canonical_folder_id IS NOT NULL`) para representar carpetas y archivos que ya existen físicamente en un sub-storage. Cuatro generaciones de backfill (`backfill_file_mirrors`, `repair_folder_mirrors_backfill`, `backfill_v4_subfile`, `backfill_file_mirrors_v2`) usaron reglas de rebase distintas y mutuamente incompatibles:

- 1.346.763 mirrors quedaron con `base_path_snapshot IS NULL` (94% de los file mirrors) → `physicalPathNormalized()` devuelve NULL.
- 747.211 mirrors tienen `parent_id` en un storage distinto al propio → no son navegables por `parent_id`.
- **Las 1.455.875 fallan la verificación física**: ninguna resuelve a un archivo real en disco.

Síntomas en vivo (reproducidos con Playwright como usuario final, 2026-09-19):

| Ruta | Resultado |
|---|---|
| `Mis Archivos > 200_Diarios > Portafolio > 20260918 > imagenes` | **vacío** (0 de 16 PNGs) |
| `GET /files/8437007/download` (mirror) | **HTTP 400 `Invalid file path`** |
| `GET /files/7800803/download` (mirror) | **HTTP 400 `Invalid file path`** |
| `GET /files/7635571/download` (canónico) | HTTP 200 |

La causa raíz no es "faltan mirrors": es que se **materializó identidad que ya se resuelve en tiempo de lectura**. `StorageSyncService::resolveListingTargets()`, `FolderListingService::resolveFolderIds()` y `PublicShareController::isDescendantOf()` ya resuelven cross-storage por path físico y funcionan (probado: `GET /files?parent_id=7635570` devuelve los 16 PNGs). Los mirrors son una segunda fuente de verdad que compite con ellos y los corrompe.

Esto viola `mis_archivos_anti_duplicidad_principle`: 3.010.250 filas representan solo ~808.824 identidades físicas distintas.

## What Changes

- **Eliminar las 1.455.875 mirror rows** de `files` (folder + archivo). Una identidad física = una fila.
- **Repuntar las FKs** antes del borrado: 78.547 `transcriptions.file_id` y 33 `shares.file_id` apuntan hoy a mirrors → al canónico.
- **Reparentar los 309.394 hijos canónicos de folder-mirrors**: los mismos-storage (308.221) son *delegation leaks* y se delegan al sub-storage vía la lógica existente de `self-healing-sync`; los cross-storage se reparentan al folder canónico equivalente.
- **Extender `resolveListingTargets()`** para cubrir el caso raíz-de-sub-storage (`relativeToSub === ''` → `parent_id = null`), hoy inexistente porque los mirrors lo tapaban.
- **Adoptar el storage de la fila devuelta** en el frontend: `navigateToFolder()` (`index.blade.php:669`) fija `currentStorage` y por eso el siguiente request consulta `storage_id=37&parent_id=<fila de st44>` → vacío.
- **Canonicalizar antes de tocar disco** en `download()`, `preview()` y `downloadFolder()` (`FileController.php:882`), que hoy construyen `base_path . '/' . path` con el row recibido.
- **Retirar el linker**: `FileMirrorLinker`, los dos comandos de backfill de mirrors y la escritura de `canonical_folder_id` en `FileObserver`.
- **Column `canonical_folder_id` deprecada por un ciclo de release** (nullable, sin escrituras nuevas); el `drop` queda en un change posterior. Esto da rollback sin pérdida de esquema.
- **Supersede** el change en curso `files-mirror-consolidation` (22/25), cuya premisa —crear más mirrors— es la que produjo el daño.

## Capabilities

### New Capabilities

- `file-physical-identity-resolution`: resolución canónica de identidad física en tiempo de lectura (listado cross-storage, descarga, shares) sin filas materializadas; contrato de "una identidad física = una fila canónica".

### Modified Capabilities

- `files-mis-archivos-sub-storage-listing`: el listado SHALL resolver también el caso raíz-de-sub-storage y el frontend SHALL adoptar el storage de la fila devuelta.
- `self-healing-sync`: la delegación de archivos mal ubicados SHALL ser el único mecanismo de reubicación (reemplaza la creación de mirror rows).
- `share-folder-identity-canonical`: la canonicalización de shares SHALL resolverse por path físico, no por `canonical_folder_id`.

## Impact

**Migración requerida** (sí): repunte de FKs + reparent + borrado de 1.455.875 filas. Ejecutable vía comando con `--dry-run`, no vía migración Laravel (volumen y necesidad de auditoría).

**Archivos afectados**: `StorageSyncService` (`resolveListingTargets`, `createFileFromScan`), `FileController` (`download`, `preview`, `downloadFolder`, `index`), `PublicShareController`, `FolderListingService`, `FilePhysicalIdentity`, `FileObserver`, `resources/views/files/index.blade.php`, y el retiro de `FileMirrorLinker` + `FilesRepairFileMirrorsCommand` + `RepairFolderMirrorsCommand`.

**No-goals**:
- No se toca `transcriptions.source_absolute_path` (identidad física del transcriptor es independiente).
- No se toca `storage_providers.parent_storage_id` (jerarquía de storages intacta).
- No se fusionan storages duplicados (eso es `storages:merge-duplicates`, ya existente).
- No se dropea la columna `canonical_folder_id` en este change.

**Riesgos**: (R1) borrar la fila equivocada pierde un archivo físico → mitigado con dry-run + `file_mirror_audit_log` + verificación pre-borrado de que existe un canónico vivo. (R2) reparent cross-storage deja `parent_id` apuntando a otro storage → el listado es por `whereIn(parent_id)`, no por storage, así que es correcto; se valida con harness. (R3) rollback requiere restaurar filas → se conserva snapshot de ids borrados en `file_mirror_audit_log`.
