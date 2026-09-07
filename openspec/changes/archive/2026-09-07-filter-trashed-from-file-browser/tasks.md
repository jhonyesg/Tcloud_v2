## 1. Patch en FileController@index

- [x] 1.1 Localizar el bloque en `app/app/Http/Controllers/FileController.php` línea ~168 donde se construye `$query = File::query();`.
- [x] 1.2 Cambiar a `$query = File::query()->where('is_trashed', false);`.
- [x] 1.3 Verificar que el resto del flujo (rama `parentId !== null` vs `whereNull('parent_id')`, orden por `is_folder desc, created_at desc`) sigue igual.

## 2. Verificación con Playwright

- [x] 2.1 Escribir `tests/playwright_filter_trashed_from_browser.py`: login → para cada storage que tenga items trashed, fetch `/files?storage_id=X` JSON → assert que ningún item retornado tiene `is_trashed=true` (o equivalentemente, que la cantidad de items con `is_trashed=true` en el JSON es 0).
- [x] 2.2 Regresión: `playwright_papelera_view.py`, `playwright_papelera_help_panel.py`, `playwright_papelera_cache_invalidation.py` — todas pasan.

## 3. Archive

- [x] 3.1 Sync del spec delta a `openspec/specs/trash-module/spec.md`.
- [x] 3.2 `mv openspec/changes/filter-trashed-from-file-browser openspec/changes/archive/2026-09-07-filter-trashed-from-file-browser/`.
