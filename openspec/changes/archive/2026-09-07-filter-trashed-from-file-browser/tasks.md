## 1. Patch en FileController@index

- [x] 1.1 Localizar el bloque en `app/app/Http/Controllers/FileController.php` línea ~168 donde se construye `$query = File::query();`.
- [x] 1.2 Cambiar a `$query = File::query()->where('is_trashed', false);`.
- [x] 1.3 Verificar que el resto del flujo (rama `parentId !== null` vs `whereNull('parent_id')`, orden por `is_folder desc, created_at desc`) sigue igual.

## 2. Verificación con Playwright

- [x] 2.1 Escribir `tests/playwright_filter_trashed_from_browser.py` con escenarios:
  - Trash pre-condition (papelera vacía)
  - Trash un archivo → reload `/files` root → el archivo NO aparece (filter activo)
  - `/papelera` SÍ muestra el archivo (regression)
  - Subfolder listing del parent original NO muestra el archivo trashado
  - Restore devuelve 200 (DB-level check; cache-level queda como follow-up)
- [x] 2.2 Regresión: `playwright_papelera_view.py`, `playwright_papelera_help_panel.py`, `playwright_papelera_cache_invalidation.py` — todas pasan (39/39 aserciones).

## 3. Bug colateral encontrado y corregido

- [x] 3.1 El fix de cache invalidation (`fix-papelera-cache-invalidation-on-trash`) usaba `$file->getOriginal('parent_id')` DESPUÉS de `softTrash()`, pero Laravel sincroniza el `original` array dentro de `update()`, así que devolvía el valor POST-update (NULL). Capturar `$originalParentId = $file->parent_id` ANTES del `softTrash()`.
- [x] 3.2 Validado: `redis-cli GET folder_gen:5:4806261` ahora bumpea correctamente (generación 1 después del trash) cuando se trasha un archivo en Disco_G.

## 4. Archive

- [x] 4.1 Sync del spec delta a `openspec/specs/trash-module/spec.md`.
- [x] 4.2 `mv openspec/changes/filter-trashed-from-file-browser openspec/changes/archive/2026-09-07-filter-trashed-from-file-browser/`.

## Resultados de validación

- Storage 5 root listing: 11 files (antes: 12 con leak de `bootTel.dat` con `is_trashed=true`)
- Disco_G subfolder: 5 items sin leak (antes: 5 items con `bootTel.dat` aún listado vía cache stale)
- Cache generation counters: `folder_gen:5:4806261` y `folder_gen:5:null` bumpan correctamente tras trash
- 11/11 aserciones en `playwright_filter_trashed_from_browser.py`
- Regresiones: 11/11 + 17/17 + 11/11 de los suites anteriores
