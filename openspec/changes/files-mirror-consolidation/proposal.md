> **⚠️ SUPERSEDED (2026-09-19) por `files-mirror-elimination`.**
>
> Este change asumía que la solución era **materializar más mirror rows**. La verificación
> en producción mostró que las 1.455.875 mirror rows creadas **no resuelven ninguna a un
> archivo real en disco** (94% sin `base_path_snapshot`, 747.211 con `parent_id` en otro
> storage) y que `GET /files/{mirror}/download` devuelve **HTTP 400 `Invalid file path`**.
> La identidad física ya se resuelve en tiempo de lectura vía
> `StorageSyncService::resolveListingTargets()`, `FolderListingService::resolveFolderIds()`
> y `PublicShareController::isDescendantOf()`.
>
> No archivar como completado. Ver `openspec/changes/files-mirror-elimination/`.

## Why

`files` tiene **mirror rows no consolidadas** entre storages padre/hijo. Cuando un folder existe físicamente en un sub-storage (ej. `206 Portafolio` con base_path `.../Prensa/Portafolio`), el sync crea **una fila adicional** en el storage padre (`200_Diarios` con base_path `.../Prensa`) representando el mismo folder físico vía path relativo (`Portafolio/20260918`). El problema: solo las mirror rows de **folders** se crean; los **archivos** (PNGs, PDFs, MP3s) NO se reflejan, solo existen en el storage canónico.

**Caso real (incidente 2026-09-19, reportado por usuario)**:
- Storage 44 (`206 Portafolio`) tiene los files reales: 16 PNGs dentro de `20260918/imagenes/`, PDFs `Portafolio_*.pdf`.
- Storage 37 (`200_Diarios`) tiene mirror rows del folder `Portafolio/20260918` PERO con **0 archivos** indexados bajo él.
- Cuando el usuario navega `200_Diarios > Portafolio > 20260918`, ve solo el folder `imagenes/` (sin PNGs adentro), aunque el disco tiene 16 imágenes.
- Click en Actualizar no mejora la situación porque el sync solo crea mirror rows de folders, no de files.

Esto causa tres síntomas:
1. **Vista incompleta desde storage padre**: el usuario piensa "faltan archivos" cuando en realidad están en el sub-storage.
2. **Cache de folder listing stale**: el contador de items por folder está mal (1 vs 17 reales).
3. **Shares públicos rotos**: si el operador crea un share sobre `200_Diarios/Portafolio/20260918/`, el destinatario NO puede descargar los PNGs porque el `PublicShareController::isDescendantOf()` no resuelve cross-storage correctamente para archivos.

El change `files-canonical-owner-by-storage` (archivado 2026-09-18) arregló la columna `owner_id` pero **no toca este problema de identidad física**.

## What Changes

- **Modelo de identidad de folder canónico**: agregar columna `files.merged_into_id` (FK self-ref, ON DELETE SET NULL). `NULL` = canónico, `NOT NULL` = mirror que apunta al canónico.
- **Helper `FilePhysicalIdentity::canonicalFor(File $folder): File`** que retorna el canónico via `physicalPathNormalized()` matching, no por `parent_id` walk (que falla cross-storage).
- **Sync de mirror rows completo**: `StorageSyncService::syncFolder` crea mirror rows para **folders Y files** al detectar match cross-storage. Antes solo creaba folders.
- **`PublicShareController::isDescendantOf` con fallback `physicalPathNormalized`**: ya implementado en `2026-09-18-fix-public-share-access-cross-storage`, reforzar contrato.
- **Comando `files:repair-folder-mirrors --apply`** que escanea BD vs disco y crea mirror rows faltantes para files (no solo folders).
- **Harness `files_mirror_consolidation`** con 6 secciones: detección de mirrors faltantes, validación cross-storage, reparación idempotente, smoke test de navegación desde storage padre.
- **No-op si storage sin disco**: el comando maneja storages remotos (S3) sin iterar filesystem.

## Capabilities

### New Capabilities

- `files-physical-folder-identity`: define el modelo de identidad física de folder, su resolución cross-storage, la propagación de mirror rows y los efectos sobre queries de listing.

### Modified Capabilities

- `share-folder-canonical-wiring`: agrega que al crear un share sobre un folder mirror, el sistema resuelve el canónico via `merged_into_id` (no `parent_id` walk) y crea el share sobre el canónico. Refuerza el contrato existente de `share-folder-canonical-wiring`.
- `self-healing-sync`: agrega que el sync crea mirror rows para **files** (no solo folders) cuando detecta match cross-storage via `physicalPathNormalized()`.
- `files-canonical-owner`: integra con `merged_into_id` — al asignar owner canónico, NO se asigna a mirror rows; el canónico absorbe la responsabilidad.

## Impact

**Archivos a modificar**:
- `app/app/Models/File.php` — agregar relación `mergedInto()` + accessor `isFolderMirror()`
- `app/app/Services/FilePhysicalIdentity.php` — nuevo helper (canonización via physical path)
- `app/app/Services/StorageSyncService.php` — `syncFolder` extendido para crear mirror rows de files
- `app/app/Http/Controllers/PublicShareController.php` — reforzar uso de `mergedInto` en showFolder
- `app/app/Console/Commands/RepairFolderMirrorsCommand.php` — nuevo comando
- `app/database/migrations/2026_09_19_*_add_merged_into_id_to_files.php` — nueva columna + FK
- `app/tests/harness_files_mirror_consolidation.php` — 6 secciones, ~25 aserciones
- `AGENTS.md` — actualizar invariante de folder identity

**Archivos NO modificados** (verificados seguros):
- `FileController::checkFilePermission` — ya funciona via storage permissions, no le importa mirror rows
- `Mis Archivos listing` — actualmente solo muestra files en `storage_provider_id = $userStorageIds`, NO cruza storages. Es el comportamiento actual.

**Riesgos críticos**:
- [R1] El comando `files:repair-folder-mirrors --apply` puede crear cientos de miles de mirror rows en una sola corrida (estimación: ~500k archivos en sub-storages Portafolio, Prensa, etc.). Tiempo estimado: 5-15 min con `session_replication_role = replica`.
- [R2] Si dos storages tienen el mismo `physical_path_normalized` pero son archivos diferentes (raro pero posible), el helper puede fusionarlos incorrectamente. Mitigación: el helper verifica que `base_path_snapshot + path` sea idéntico, no solo `path`.
- [R3] La columna `merged_into_id` agrega complejidad al modelo `File`. Cualquier nuevo código que haga queries sobre files debe considerar que puede recibir un mirror. Mitigación: helper central `File::canonicalFor()` + tests de regresión exhaustivos.

**No-goals**:
- No se eliminan las mirror rows existentes (se mantienen como antes, pero ahora con `merged_into_id` apuntando al canónico).
- No se cambia el modelo de permisos (sigue `user_storages`).
- No se fusionan storages duplicados (eso sería el change `2026-09-08-schema-clarity-rename-merged-into` ya archivado).
