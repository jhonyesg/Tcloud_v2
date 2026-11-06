## Why

El modal "Mover a..." / "Copiar a..." de Mis Archivos dice "No hay subcarpetas aquí" cuando navegas desde una carpeta que SÍ tiene subcarpetas visibles en el listado. El bug rompe el flujo principal de mover archivos entre carpetas y obliga a hacerlo vía API.

Causa raíz: el endpoint `/files?storage_id=X` ahora retorna `{files, breadcrumbs, pagination}` (shape formalizado por `mis-archivos-fs-first`), pero el handler de Alpine `loadCopyMoveFolders` en `resources/views/files/index.blade.php:1156` trata la respuesta como si fuera un array directo. Como la respuesta es un objeto, `Array.isArray(data)` da `false` y `copyMoveFolders` queda `[]`. El mismo patrón ya está aplicado correctamente en otros 3 call-sites del mismo módulo (líneas 440 y 1274); solo este quedó atrás.

Además del fix del shape, la UX actual obliga a navegar desde la raíz del storage para mover un archivo, lo cual es ruidoso a partir de 2 niveles de profundidad. El modal debe abrir en el folder padre del archivo a mover y ofrecer un control "↑ Subir" para retroceder si el destino es otra rama del árbol.

## What Changes

- **Fix del shape en `loadCopyMoveFolders`**: leer `data.files ?? []` cuando la respuesta es objeto (mismo patrón que líneas 440 y 1274).
- **El modal abre en el folder actual del usuario** (`currentFolder`), no en la raíz del storage. El destino default es "aquí" (mismo nivel que el archivo a mover).
- **Botón "↑ Subir"** en el modal para retroceder al padre del destino actual; el endpoint `/files/{id}` ya devuelve `parent_id` en 1 round-trip.
- **El backend no cambia**: ya valida `isDescendantOf()` en `FileController::move` (línea 1126) y retorna 422 para carpetas dentro de sí mismas; el frontend deshabilita el item directo (línea 3553) como hint visual, y el backend es el guardrail hard.
- **Sin nuevos endpoints**: `/files`, `/files/{id}` y `/files/{id}/move` ya existen.
- **Sin cambio de modelo**: no requiere migration.

## Capabilities

### Modified Capabilities
- `file-copy-move`: el modal de selección de carpeta destino abre en el folder actual del usuario (no en la raíz del storage) y ofrece control "Subir" para navegar al padre. Se agrega requirement que documenta el shape `{files, breadcrumbs, pagination}` que consume el frontend.

## Impact

- **Frontend**: `resources/views/files/index.blade.php` (3 cambios pequeños: openCopyMoveModal, loadCopyMoveFolders, navigateCopyMoveUp nuevo).
- **Backend**: ninguno. `FileController::index`, `::move`, `::copy` quedan como están.
- **Rutas afectadas**: ninguna.
- **Migración**: ninguna.
- **Cache**: la cache de `folder_listing:{storageId}:{pid}:{gen}:{page}` ya cubre el endpoint `/files` que invoca el modal; el fix no introduce latencia nueva.
- **Compatibilidad**: no breaking. La operación backend es idéntica; solo cambia la UX del modal.

## Non-goals

- No se cambia la operación backend (move/copy siguen funcionando igual).
- No se agrega soporte cross-storage (mover entre storages distintos) — sigue siendo dentro del mismo storage.
- No se cambia el guardrail anti-descendencia (el backend `FileController::move:1126` valida con `isDescendantOf()`; el disable visual en UI es solo cosmético).
- No se introduce un árbol de descendientes en frontend (caro para carpetas grandes); el guardrail del backend cubre.