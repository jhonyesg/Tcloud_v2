## Why

`FolderListingService::resolveFolderIds()` (introducido por el change `2026-09-17-share-folder-canonical-wiring`) tiene **dos bugs encadenados** que producen el síntoma reportado por el operador: "genero un link nuevo en Mis Archivos y al cargarlo en otro lugar sale vacío".

### Bug 1: Nietos filtrados como hijos (descubierto primero)

La implementación mezcla dos conceptos distintos en el mismo set: la identidad del folder (canonical_id + IDs de mirrors) y los IDs de los **hijos directos** del folder. Al pasar ese set a `whereIn('parent_id', $folderIds)`, cuando el folder navegado tiene sub-folders como hijos, sus IDs quedan en el set y la query captura también los nietos. Resultado verificado en BD de producción:

| Folder | Hijos reales | Listing actual (buggy) |
|---|---|---|
| `Sol` (id 1524, storage 47) | 121 sub-folders | **11,531** (nietos) |
| `Cache` (id 7346139, storage 36) | 2 sub-folders | **17,898** (nietos) |

### Bug 2: Folders equivalentes por path físico no se encuentran (causa real del "vacío" reportado)

`FolderListingService` solo conoce la identidad de un folder vía `merged_into_id` (mirrors). Pero en producción existen folders que representan el **mismo path físico** (mismo `base_path_snapshot + path`) en storages distintos sin estar enlazados como mirrors. Caso real del share `1a35201a224b75da143ced0583b2b5b9`:

```
Storage 5 (00 Discos) base_path=/.../Tcloud
  Disco_D/backup/02_Canal_Rcn_bk/18092026 = folder 7631760  ← SHARE APUNTA AQUÍ (vacío)
Storage 34 (30 Television Bk) base_path=/.../Tcloud/Disco_D/backup  (más específico)
  02_Canal_Rcn_bk/18092026 = folder 7631759  ← los 35 archivos viven aquí
```

Ambos folders tienen el MISMO `physical_path_normalized` pero `merged_into_id` es NULL en ambos (un auto-sync previo movió los archivos al sub-storage sin re-vincular). Antes del Bug 1, el listado "casualmente" devolvía 35 archivos porque el cross-match por parent_id filtraba las rows huérfanas; tras corregir Bug 1, el listado devuelve 0.

## What Changes

- **Fix Bug 1** en `FolderListingService::resolveFolderIds()`: eliminar la línea que mezcla los hijos directos del canonical en el set de identidades de folder.
- **Fix Bug 2** en el mismo método: añadir búsqueda de folders con el mismo `physical_path_normalized` (= `LOWER(RTRIM(base_path_snapshot || '/' || path))`). Cubre el caso de folders equivalentes en storages distintos que nunca fueron enlazados como mirrors.
- **Sin migración de BD** (es bug de lógica, no de esquema).
- **Sin cambio de modelo** (la columna `merged_into_id` se mantiene; el cambio `schema-clarity-rename-merged-into` sigue su curso independiente).
- **Harness extendido**: agregar escenario (b.3) que cubre el caso "mismo path físico, sin mirror linkage" — exactamente el escenario del share reportado.

El fix aplica a TODOS los shares de carpeta — existentes y nuevos — porque `FolderListingService::listContents()` se invoca en cada request a `PublicShareController::show` y `::folder` sin caché de listado a nivel de share.

## Capabilities

### Modified Capabilities

- `share-folder-identity-canonical`: el requirement "Public share page lists children across storages" ahora exige que el listado encuentre el equivalente vía mirror identity Y vía `physical_path_normalized`. Se agrega scenario explícito que cubre el caso del share reportado.

## Non-goals

- **NO** se renombra `merged_into_id` (es trabajo del change in-progress `schema-clarity-rename-merged-into`).
- **NO** se cambia `dedupeByName` (funciona correctamente una vez que el set de entrada es correcto).
- **NO** se cambia `FileController::browse` (Mis Archivos usa otra lógica via `resolveListingTargets` que ya maneja cross-storage).
- **NO** se reescribe `FolderListingService` completo (los dos bugs son puntuales).
- **NO** se cambia el flujo de creación de shares (canonicalización en `ShareController::store` ya funciona para el caso mirror).
- **NO** se agrega caché de listado al share (sería un parche, no solución de raíz).

## Impact

- `app/app/Services/FolderListingService.php` — 1 línea eliminada (Bug 1) + 1 bloque nuevo de ~15 líneas (Bug 2).
- `app/tests/harness_share_folder_canonical.php` — 1 escenario nuevo (b.3) + ajustes de cleanup.
- `app/tests/verify_folder_listing_fix.php` — verificación contra 5 folders reales de producción (incluye el del share reportado).
- `openspec/specs/share-folder-identity-canonical/spec.md` — delta con scenarios adicionales.
- Cero migraciones, cero cambios de schema, cero cambios en controllers, modelos, comandos ni workers.
- Blast radius: el método `resolveFolderIds` es público y tiene 2 callers reales (`PublicShareController::show`, `::folder`). Ambos pasan el folder que el operador seleccionó, así que ambos se benefician automáticamente.

## Reversibilidad

`git revert` del commit devuelve el comportamiento bug. Una segunda línea de defensa existe: si el operador quiere pausar el efecto del fix sin reverter, basta con cachear `resolveFolderIds` con un TTL corto (NO implementado en este PR; se documenta como rollback sin deploy).
