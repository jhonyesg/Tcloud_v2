## Context

Ver `proposal.md — Why` para la motivación y la evidencia en vivo.

**Estado actual relevante** (medido 2026-09-19 sobre `tcloudstorage`):

| Métrica | Valor |
|---|---|
| Filas en `files` (`deleted_at IS NULL`) | 3.010.250 |
| Identidades físicas distintas (`lower(rtrim(base_path \|\| '/' \|\| path))`) | 808.824 |
| Mirror rows (`canonical_folder_id IS NOT NULL`) | 1.455.875 (1.429.398 archivo + 26.477 carpeta) |
| Mirrors con `base_path_snapshot IS NULL` | 1.347.108 (94%) |
| Mirrors con `parent_id` en otro storage | 747.211 |
| Mirrors que resuelven a un archivo real en disco | **0** |
| Hijos canónicos de un folder-mirror | 309.394 (308.306 archivo + 1.088 carpeta) |
| …de esos, en el **mismo** storage que el mirror | 219.647 (delegation leaks) |
| …de esos, en **otro** storage | 89.747 |
| `transcriptions.file_id` apuntando a un mirror | 78.547 |
| `shares.file_id` apuntando a un mirror | 33 |
| Filas canónicas sin `base_path_snapshot` | 744.126 |

Generaciones de backfill coexistentes, con reglas de rebase incompatibles:

```
repair_folder_mirrors_backfill  681.731   path relativo correcto al storage propio
backfill_file_mirrors           747.230   path rebasado a un storage ancestro (roto)
backfill_v4_subfile                 256   path del canónico copiado sin rebase + case lowercased (roto)
backfill_file_mirrors_v2            141   mixto
mirror_linker_reconcile              40   link en vivo
```

**Restricciones**:
- No se puede dropear `canonical_folder_id` en este change (rollback sin pérdida de esquema).
- `checkFilePermission` (change `fix-self-healing-permission-leak`) ya autoriza por `storage_provider_id + user_storages`; no depende del link materializado.
- `public-share-access-cross-storage` ya implementó el fallback por path físico en `isDescendantOf()`.
- El servidor es nginx + PHP-FPM; el frontend es Blade + Alpine.js sin build step.

## Goals / Non-Goals

**Goals:**
- Reducir `files` a una fila por identidad física, eliminando 1.455.875 espejos sin perder ninguna transcripción ni share.
- Dejar el listado, la descarga y los shares resolviendo cross-storage por path físico.
- Cerrar el caso raíz-de-sub-storage (`relativeToSub === ''`) que hoy no está cubierto.
- Preservar la navegación de la ruta reportada: `200_Diarios > Portafolio > 20260918 > imagenes` debe mostrar los 16 PNGs.

**Non-Goals:**
- No se elimina la columna `files.canonical_folder_id` (queda deprecada, 0 filas no nulas).
- No se consolida la jerarquía de `storage_providers` (eso es `storage-physical-identity` / `storages:merge-duplicates`).
- No se toca `transcriptions.source_absolute_path` (identidad física del transcriptor).
- No se eliminan los 30.257 duplicados de identidad física entre filas **canónicas** (requiere decisión del operador sobre cuál gana y qué share apunta a cuál); se reportan.

## Decisions

### Decisión 1: Resolver identidad en lectura, no materializarla

**Elegido**: el listado y la descarga comparan `lower(rtrim(base_path || '/' || path))`. Una fila por archivo físico.

**Alternativa A — reparar los mirros materializados**: rebasar los 1.4M paths, backfillear 1.35M snapshots, corregir 4 generaciones y mantener 4 invariantes para siempre. Cada nuevo camino de sync puede volver a romperlo. **Rechazada**: los mirrors ya demostraron ser infalsificables en mantenimiento (0 de 1.455.875 resuelven bien).

**Alternativa B — columna desnormalizada `physical_path_normalized` en `files` con índice único parcial**: más barata de queryar que `lower(rtrim(...))`. **Diferida**: hay 30.257 identidades canónicas duplicadas que harían fallar el UNIQUE; primero hay que consolidarlas (fuera de alcance). Se evaluará en un change posterior.

### Decisión 2: El frontend adopta el storage de la fila devuelta

`resources/views/files/index.blade.php`, estado Alpine `currentStorage` / `currentFolder`:

`navigateToFolder(folderId, folderName)` hoy hace `this.currentFolder = folderId` sin tocar `currentStorage`. Como el listado cross-storage devuelve filas de otro storage, el siguiente request combina el storage viejo con el `parent_id` nuevo → vacío.

**Elegido**: `navigateToFolder(folderId, folderName, storageId)` acepta el storage de la fila y hace `this.currentStorage = storageId` cuando difiere. El mismo `storageId` ya viene en cada fila del payload (`files[].storage_provider_id`), así que el cambio es de propagación, no de contrato.

**Alternativa**: que el backend ignore `storage_id` cuando `parent_id` está presente. **Rechazada**: `storage_id` es necesario para permisos, banner de storage y TTL de cache; ignorarlo rompería el scoping.

### Decisión 3: `resolveListingTargets` cubre el path relativo vacío

`StorageSyncService::resolveListingTargets()` (`StorageSyncService.php:472`) calcula:

```php
$relativeToSub = ltrim(substr($absolutePath, strlen($subBase)), '/');
$subFolder = File::where('storage_provider_id', $sub->id)
    ->where('path', $relativeToSub)
    ->where('is_folder', true)->first();
if (!$subFolder) return $targets;   // ← aborta cuando $relativeToSub === ''
```

Cuando la carpeta navegada **es** la raíz del sub-storage, `$relativeToSub === ''` y no hay fila con `path = ''`, así que retorna sin incluir el sub. **Elegido**: tratar `''` como `parent_id = null` dentro del sub-storage y añadir ese target.

### Decisión 4: Canonicalizar en lectura resuelve al canónico por path, no por FK

`FilePhysicalIdentity::canonicalFor()` hoy solo sigue `canonical_folder_id`. Tras el retiro, la columna queda en 0 y el método debe resolver por identidad física: devolver la fila cuya `base_path` es más específica entre las que comparten `physical_path_normalized`.

**Elegido**: reescribir `canonicalFor()` para resolver por path físico (con cache Redis TTL 300s, invalidado por `FileObserver`). Mantiene la firma, así los call sites de `ShareController` y `PublicShareController` no cambian.

**Nota sobre `base_path_snapshot`**: 744.126 filas canónicas no lo tienen. El resolver cae a `storage_providers.base_path` vía JOIN cuando el snapshot es NULL (`FileMirrorLinker::physicalPathFor()` ya usa ese fallback). El comando de migración corre `files:resync-base-path-snapshots --apply` como paso previo.

### Decisión 5: Orden de la migración de datos

El orden importa porque cada paso puede invalidar el siguiente:

```
1. resync-base-path-snapshots --apply        (llena snapshots faltantes)
2. repuntar FKs  (transcriptions, shares)    → del mirror al canónico
3. reparentar hijos cross-storage            (89.747) al folder canónico equivalente
4. delegar hijos mismo-storage               (219.647) vía files:repair-delegation-leak
5. re-verificar: 0 espejos con hijos y sin canónico vivo
6. borrar mirrors  (soft-delete + audit log)
7. verificar invariantes
```

Cada paso audita en `file_mirror_audit_log` (append-only, un `action` por tipo: `repoint_fk`, `reparent_child`, `retire_mirror`). El paso 6 usa `deleted_at` (soft-delete) para que el rollback sea un `UPDATE deleted_at = NULL` sobre los ids registrados en el audit log, no una restauración de backup.

### Decisión 6: Retiro de código, no solo de datos

Se eliminan `FileMirrorLinker`, `FilesRepairFileMirrorsCommand`, `RepairFolderMirrorsCommand`, y la escritura de `canonical_folder_id` en `FileObserver` (queda solo el mantenimiento de `base_path_snapshot`). Esto evita que un sync futuro vuelva a materializar espejos.

`File::isFolderMirror()`, `File::canonicalFolder()` y los aliases `isMirror()`/`canonical()` se mantienen como **deprecated** por un ciclo (retornan `false`/`$this`), para no romper call sites de terceros. Su eliminación va en el change del `drop` de columna.

### Decisión 7: Verificación con Playwright como cliente final

El harness de regresión extiende el patrón de `tests/e2e/files-storages.spec.mjs`:

- Login con usuario no-admin con acceso al sub-storage (para probar que la resolución de lectura + permisos funciona sin el fallback de admin).
- Navegación click-through de la ruta reportada.
- Assert de los 16 PNGs visibles.
- Assert de `GET /files/{id}/download` → 200 y bytes > 0 para un archivo servido cross-storage.
- Assert de la consola sin errores y de que ningún request devuelve 400/403.

## Risks / Trade-offs

**[R1] Borrar la fila equivocada pierde un archivo físico** → El paso 6 solo retira filas que (a) tienen `canonical_folder_id IS NOT NULL`, (b) ya no tienen hijos (`parent_id` reparentado en pasos 3-4), y (c) tienen un canónico vivo con la misma identidad física. Cualquier fila que falle (c) se reporta como `unresolvable_mirror` y **no** se retira.

**[R2] Reparent cross-storage deja `parent_id` apuntando a un row de otro storage** → El listado consulta `whereIn('parent_id', $ids)`, no `where('storage_provider_id', $sid)`, así que un `parent_id` cross-storage es válido. Lo valida el harness sección 4.

**[R3] La resolución en lectura es más costosa que un FK** → `lower(rtrim(base_path || '/' || path))` no usa índice. Mitigación: índice funcional `files_physical_path_normalized_idx` sobre la expresión (no único, por los 30.257 duplicados canónicos). La cache de `canonicalFor()` es TTL 300s.

**[R4] 89.747 hijos cross-storage reparentados pueden quedar sin folder canónico equivalente** → Si no existe, `parent_id = NULL` + log `files.mirror_retirement.orphan_parent`. El archivo sigue accesible por búsqueda y por path físico, aunque no por navegación de árbol. El comando reporta el conteo para que el operador decida.

**[R5] Rollback incompleto si se corre `--apply` varias veces** → El comando es idempotente (NOT EXISTS sobre `canonical_folder_id IS NOT NULL`), y el audit log registra los ids retirados por corrida, así que el rollback restaura exactamente lo retirado.

**[R6] El retiro de código deja referencias huérfanas** → `rg "FileMirrorLinker|canonical_folder_id"` debe retornar solo las referencias marcadas `@deprecated`. Se valida en la tarea final.

## Migration Plan

**Pre-deploy** (con el código actual, sin cambios):
```bash
cd app
php artisan files:resync-base-path-snapshots --dry-run
php artisan files:mirror-retirement --dry-run          # comando nuevo
```

**Deploy**: `git merge` + `systemctl reload php84-php-fpm` (no hay workers que reiniciar; el cambio no toca `transcriptions`).

**Post-deploy**:
```bash
php artisan files:mirror-retirement --apply --batch=5000
php artisan files:resync-base-path-snapshots --apply      # snapshots de las filas reparentadas
php artisan dashboard:clear-cache
```

**Verificación**:
```bash
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "
SELECT count(*) FROM files WHERE canonical_folder_id IS NOT NULL AND deleted_at IS NULL;"
# Esperado: 0
```

**Rollback**:
```bash
git revert <commit-hash>
php artisan files:mirror-retirement --revert            # restaura deleted_at = NULL por audit log
systemctl reload php84-php-fpm
```
La columna nunca se dropea, así que el código viejo puede volver a leer `canonical_folder_id` sin migración.

**Ventana de mantenimiento**: no requerida. El listado ya resuelve cross-storage durante la migración; el único efecto intermedio es que algunas carpetas pueden mostrar filas duplicadas por nombre hasta que el paso 6 complete (la dedup por peso las colapsa).

## Open Questions

- **Consolidación de los 30.257 duplicados canónicos**: ¿gana el de `base_path` más específico, o el que tiene más transcripciones linkeadas (como en `StorageProvider::canonicalFor()`)? Afecta el índice único parcial futuro, no este change. Se puede decidir con el reporte del comando.
- **Índice funcional**: si la expresión `lower(rtrim(base_path || '/' || path))` no se usa en el planner, evaluar una columna generada `GENERATED ALWAYS AS (...) STORED`. Requiere medir primero con EXPLAIN; no cambia el contrato.
