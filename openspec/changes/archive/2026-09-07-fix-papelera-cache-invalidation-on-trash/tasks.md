## 1. Patch en FileController@destroy

- [x] 1.1 Localizar el bloque en `app/app/Http/Controllers/FileController.php` alrededor de las líneas 410-415.
- [x] 1.2 Capturar `$originalParentId = $file->getOriginal('parent_id')` ANTES de la llamada a `invalidateFolderCache` (reutilizar la expresión en la condición).
- [x] 1.3 Después de la invalidación existente del padre original, agregar una segunda invalidación solo cuando `$file->parent_id === null && $originalParentId !== null`, llamando `invalidateFolderCache($storageId, null)`.
- [x] 1.4 Remover la llamada `$service->invalidateSidebarCache(...)` que era protected → producía HTTP 500 desde el commit `9571ff5`. La invalidación del sidebar YA ocurre dentro de `PapeleraService::softTrash()`; la del controller era redundante y rota.

## 2. Verificación con Playwright

- [x] 2.1 Escribir `tests/playwright_papelera_cache_invalidation.py`: login → cargar `/files` con scope root (sin storage_id) para cachear el payload → DELETE de un archivo en subcarpeta → recargar `/files` → assert que el archivo trashado no aparece en el JSON.
- [x] 2.2 Verificar regresión: borrar archivo en root sigue funcionando (single invalidation cubre ambos casos).
- [x] 2.3 Screenshot de `/files` después del delete para evidencia.

## 3. Verificación de regresión

- [x] 3.1 Correr el suite Playwright existente (`playwright_papelera_view.py`, `playwright_papelera_help_panel.py`) — todas pasan (28/28 aserciones).

## 4. Archive

- [ ] 4.1 Sync del spec delta a `openspec/specs/trash-module/spec.md`.
- [ ] 4.2 `mv openspec/changes/fix-papelera-cache-invalidation-on-trash openspec/changes/archive/2026-09-07-fix-papelera-cache-invalidation-on-trash/`.

## Resultados de validación

- DELETE `/files/4833562` retorna 200 (antes: 500)
- Cache generation de `/files?storage_id=5` se invalida después del trash (V1 != V2)
- Archivo `bootTel.dat` aparece correctamente en `/papelera` con 15 días restantes
- Regresiones: 11/11 aserciones en `playwright_papelera_view.py` + 17/17 en `playwright_papelera_help_panel.py`
