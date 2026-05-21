## Why

La columna "Fecha" en el explorador de archivos muestra `created_at` (fecha del escaneo o upload al sistema) cuando `file_modified_at` es NULL, lo que hace incoherente el ordenamiento ASC/DESC por fecha. El campo `file_modified_at` existe en la tabla pero no se popula al subir archivos vía UI.

## What Changes

- `FileController::upload()` ahora llama `filemtime()` tras mover el archivo y guarda el resultado en `file_modified_at` al crear el registro en BD.
- `sortedFiles()` en el frontend usa exclusivamente `file_modified_at` (sin fallback a `created_at`) para ordenar.
- La columna "Fecha" en la tabla de archivos y el panel lateral muestran `file_modified_at` o `"—"` si es null, sin fallback a `created_at`.

## Capabilities

### New Capabilities
_Ninguna — el comportamiento correcto ya estaba diseñado, solo faltaba la implementación consistente._

### Modified Capabilities
- `file-upload-ux`: El endpoint de upload ahora captura `file_modified_at` desde el disco tras el `move()`.

## Impact

- **FileController.php**: método `upload()` — agregar captura de `filemtime()` y campo `file_modified_at` en `File::create()`
- **resources/views/files/index.blade.php**: función `sortedFiles()` y expresiones de display `x-text="formatDate(...)"` en tabla y panel lateral
- **No requiere migración**: la columna `file_modified_at` ya existe (migración `2026_05_13_000003`)
- **Registros existentes con NULL**: se corrigen en el próximo rescan (lógica en `StorageSyncService` ya maneja esto correctamente)

## Non-goals

- No se modifica `StorageSyncService` (ya captura `filemtime()` correctamente).
- No se lanza backfill automático de registros con `file_modified_at = NULL`.
- No se cambia el significado de `created_at` ni se expone en la UI.
