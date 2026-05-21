## Why

Los usuarios con permiso `full` en un storage no tienen forma de copiar ni mover archivos y carpetas dentro del explorador: la única opción para reorganizar contenido es descargar y volver a subir. Implementar estas operaciones completa el conjunto de acciones de gestión de archivos (renombrar, eliminar, copiar, mover) que ya se esperan en un gestor de archivos.

## What Changes

- Nuevos endpoints `POST /files/{id}/copy` y `POST /files/{id}/move` en `FileController` que operan sobre storages locales.
- Operación **copy** para archivos: copia física del archivo en disco (`copy($src, $dst)`) **y** nuevo registro en BD con `file_modified_at` desde `filemtime()`.
- Operación **copy** para carpetas: crea el directorio en disco (`mkdir`) y copia recursivamente cada archivo (`copy()`) **y** crea nuevos registros en BD para la carpeta y toda su jerarquía de hijos.
- Operación **move** para archivos: mueve el archivo en disco (`rename($src, $dst)`) **y** actualiza `path` y `parent_id` en BD.
- Operación **move** para carpetas: mueve el directorio completo en disco (`rename($srcDir, $dstDir)`, operación atómica) **y** actualiza `path` de la carpeta y de **todos sus descendientes** en BD.
- En todos los casos: la operación en disco ocurre primero; si falla, no se modifica la BD. La ruta física se resuelve como `storage.base_path + '/' + file.path`.
- Frontend: botones "Copiar" y "Mover" visibles únicamente cuando `canFull()` (permiso `full`), tanto en vista grilla como en vista tabla.
- Modal de selección de destino: árbol de carpetas del storage actual, permite seleccionar carpeta destino o raíz del storage.

## Capabilities

### New Capabilities
- `file-copy-move`: Copiar y mover archivos y carpetas entre directorios de un storage local, disponible solo para usuarios con permiso `full`.

### Modified Capabilities
_Ninguna._

## Impact

- **FileController.php**: nuevos métodos `copy()` y `move()`
- **routes/web.php**: `Route::post('/files/{file}/copy', ...)` y `Route::post('/files/{file}/move', ...)`
- **resources/views/files/index.blade.php**: nuevos helpers Alpine `canCopyMove()`, modal de destino, botones en grilla y tabla
- **No requiere migración**: no hay cambios en el esquema de BD
- Solo aplica a storages de tipo `local` (igual que download, rename, delete)

## Non-goals

- No se implementa copiar/mover entre distintos storages.
- No se implementa drag-and-drop visual para mover (queda para un change futuro).
- No se implementa portapapeles persistente (cut/copy/paste entre navegaciones).
- No se implementa para storages S3.
