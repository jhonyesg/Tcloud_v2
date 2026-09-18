## Context

`FolderListingService::resolveFolderIds()` se introdujo en el change `2026-09-17-share-folder-canonical-wiring` (PR 2) con la intención de retornar el set de IDs de folder que representan la misma identidad física (canonical + mirrors), para que `listContents()` haga un único `whereIn('parent_id', $ids)`. La implementación actual mete además los IDs de los hijos directos del canonical en ese set, lo que rompe el invariante "los IDs retornados son identidades de folder".

Ver `proposal.md - Why` para la evidencia y el síntoma. Este diseño explica cómo corregir el bug con el mínimo blast radius.

## Goals / Non-Goals

**Goals:**
- Restaurar el invariante: `resolveFolderIds()` retorna **solo** IDs de identidad de folder (canonical + mirrors), no IDs de filas hijas.
- Cubrir el escenario de regresión con un harness que valide el caso "hijos son sub-folders + existen nietos" (los 10 escenarios existentes NO cubrían este caso).
- Mantener backwards compatibility con todos los callers actuales (`PublicShareController::show` y `::folder`).
- Cero migración, cero cambio de modelo, cero cambio en controllers, comandos ni workers.

**Non-Goals:**
- Refactorizar `FolderListingService` completo (puede hacerse en un PR posterior si el patrón `mirror identity` se usa en más sitios).
- Cambiar `dedupeByName` (ya funciona correctamente cuando el set de entrada es el correcto).
- Optimizar el query (la cardinalidad del set es típicamente 1-3 IDs; no es cuello de botella).
- Agregar caché de listado a nivel de share (sería parche, no solución de raíz).

## Decisions

### D1. Eliminar la línea que mezcla hijos directos

**Choice:** En `FolderListingService::resolveFolderIds()`, eliminar la línea:

```php
$ids = array_merge($ids, File::where('parent_id', $canonical->id)->pluck('id')->all());
```

**Por qué gana**: la línea no pertenece a la lógica de "identidades de folder". Es un vestigio que producía cross-match con nietos (Bug 1). El fix es de una línea, semánticamente limpio, y elimina una clase entera de bugs.

### D2. Añadir búsqueda por `physical_path_normalized` (Bug 2)

**Choice:** En el mismo método, después del bloque mirror-identity, añadir una búsqueda de folder rows con el mismo `physical_path_normalized`:

```php
$targetNormalized = $folder->physicalPathNormalized();
if ($targetNormalized !== null && $targetNormalized !== '(orphan)') {
    $equivalentIds = File::where('is_folder', true)
        ->where('is_trashed', false)
        ->whereNotNull('base_path_snapshot')
        ->whereNotNull('path')
        ->whereRaw("LOWER(RTRIM(COALESCE(base_path_snapshot, '') || '/' || COALESCE(path, ''))) = ?", [$targetNormalized])
        ->pluck('id')
        ->all();
    $ids = array_merge($ids, $equivalentIds);
}
```

**Por qué gana**: cubre el caso real visto en share #764 donde un folder vacío en storage 5 representa el mismo path físico que un folder con 35 archivos en storage 34, sin que estén enlazados como mirrors. El query usa el índice `files_storage_base_path_snapshot_idx` (ya existente). El normalizador `physicalPathNormalized()` en `File` ya implementa el cálculo correcto con LOWER + RTRIM.

**Alternatives considered:**
- *Usar `resolveListingTargets(storageId, parentId)` para encontrar equivalentes*: rechazado — esa función solo busca en sub-storages (dirección padre→hijo), no en parent storages (dirección hijo→padre). Nuestro caso es bidireccional: el share puede apuntar a cualquier storage de la cadena.
- *Forzar un mirror-link post-sync para todos los folders equivalentes*: rechazado — agrega estado mutable nuevo y requiere migración para los ~22k mirrors existentes. La búsqueda por path es stateless y no requiere escritura.
- *Solo usar mirrors y arreglar el bug 2 vía data fix*: rechazado — el problema se reproduce cada vez que un auto-sync mueve archivos a un sub-storage sin re-vincular; el código debe ser robusto a ese escenario.

### D3. Mantener el query `whereIn('parent_id', $folderIds)` sin cambios

**Choice:** El método `listContents()` sigue usando:

```php
$query = File::query()->whereIn('parent_id', $folderIds)->where('is_trashed', false)->where(...);
```

Solo cambia qué hay dentro de `$folderIds` (ahora incluye mirrors + equivalents por path).

**Por qué gana**: una vez corregido `resolveFolderIds`, el query devuelve exactamente lo que su nombre sugiere: las filas cuyo `parent_id` es una identidad de folder (canonical, mirrors, o equivalente por path). El filtro de `is_trashed` y `availability_state != 'missing'` se mantiene porque son invariantes del dominio "archivo visible".

### D4. Tests en el harness existente

**Choice:** Extender `harness_share_folder_canonical.php` con 3 escenarios adicionales (b.1, b.2, b.3) en lugar de crear uno nuevo. Escenario b.3 cubre el caso "sin mirror, mismo path físico" — exactamente el del share reportado.

**Por qué gana**: un solo harness crece verticalmente; los escenarios se numeran y se mantienen.

### D5. Sin feature flag / sin setting de rollback

**Choice:** No se introduce `SystemSetting` para togglear el fix. Rollback es `git revert`.

**Por qué gana**: la decisión técnica es binaria (los IDs retornados son identidades de folder; el método de búsqueda es correcto). Un setting agregaría complejidad sin valor.

## Risks / Trade-offs

- **R1. Shares existentes con file_id apuntando a un mirror dangling (canónico borrado, FK SET NULL):**
  → Mitigación: verifiqué en BD que `count(*) WHERE merged_into_id IS NOT NULL AND NOT EXISTS (canonical) = 0`. No hay mirrors huérfanos actualmente. El fix no cambia este caso: `resolveFolderIds` retorna `[]` igual que antes, y `listContents` retorna colección vacía igual que antes.

- **R2. Algún caller externo (script admin, comando CLI) usa `FolderListingService::resolveFolderIds()` esperando el comportamiento buggy:**
  → Mitigación: solo hay 2 callers reales, ambos en `PublicShareController`, ambos los verifiqué manualmente. `ShowCanonicalizationImpactCommand` lo invoca solo para imprimir un conteo informativo (la línea 316 dice "Cross-storage = cuántos rows ve FolderListingService"); después del fix el conteo será el correcto (sin nietos), que es lo que el comando DEBE reportar.

- **R3. `dedupeByName` se vuelve innecesario en algunos casos:**
  → Mitigación: NO se elimina. Sigue siendo necesario para el caso donde dos storages tienen el mismo path físico con archivos distintos (dedup por nombre prefiere el storage con base_path más largo). El fix lo deja intacto.

- **R4. La corrección puede exponer otros bugs latentes (e.g., archivos con `parent_id` apuntando a un archivo, no a un folder):**
  → Mitigación: si esto ocurre, el `whereIn` ya no los capturaría (porque tras el fix los archivos no quedan en `folderIds`). El síntoma desaparecería también. Si en el futuro aparecen, se abordan en otro PR.

- **R5. Performance del query se mantiene similar:**
  → Mitigación: el set `folderIds` pasa de tener N+child_count entradas a tener solo N (canonical + mirrors). Cardinalidad típica: 1-3 IDs. El índice `idx_files_parent_id` sigue siendo el óptimo.

## Migration Plan

**Deploy (forward):**
1. Merge del PR. Sin migración de BD.
2. `systemctl reload php84-php-fpm` para liberar opcode cache (defensivo; Laravel + opcache podría tener la versión vieja cacheada por 60-300s).
3. Operador corre `php tests/harness_share_folder_canonical.php` desde `app/`. Debe pasar todos los escenarios (los 10 existentes + 2-3 nuevos) con exit code 0.
4. Smoke test manual: abrir 2-3 shares de folder y verificar que el conteo de ítems coincide con lo que Mis Archivos muestra.

**Rollback (backward):**
- `git revert <commit-hash>` revierte el cambio de una línea.
- `systemctl reload php84-php-fpm`.
- No hay estado persistente que limpiar. Los shares siguen apuntando al canónico (esa canonicalización sigue activa), solo el listado vuelve al comportamiento buggy.
- Si el operador no puede deployar inmediatamente, no hay acción de mitigación alternativa porque el bug no causa daño funcional grave (solo listas "demasiado largas" en folders con sub-folders, no pérdida de datos).

## Open Questions

None. La decisión técnica es trivial, el blast radius es de 1 archivo, el harness existente cubre el área, y la corrección se valida con queries directos a la BD.
