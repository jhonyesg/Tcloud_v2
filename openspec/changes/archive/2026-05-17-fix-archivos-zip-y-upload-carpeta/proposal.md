## Why

El módulo de archivos tiene dos bugs que impiden operaciones básicas: (1) descargar una carpeta genera un ZIP vacío porque el backend consulta la DB sin sincronizar sub-carpetas primero, y (2) no existe soporte para subir carpetas completas — solo archivos individuales.

## What Changes

- **Fix descarga ZIP**: Reemplazar la consulta recursiva a la DB en `downloadFolder` por lectura directa del filesystem con `RecursiveDirectoryIterator`, eliminando la dependencia del estado de sincronización de la DB.
- **Upload de carpetas**: Agregar botón "Subir carpeta" en el modal de upload con `<input webkitdirectory>`, función `uploadFolder()` que lee `webkitRelativePath` de cada archivo, crea la estructura de carpetas en la DB y sube cada archivo al `parent_id` correcto.
- **Drag & drop de carpetas**: Reemplazar `dataTransfer.files` por `dataTransfer.items` + `FileSystemEntry` API para capturar carpetas arrastradas al área de drop.

## Capabilities

### New Capabilities
- `folder-upload`: Subida de carpetas completas conservando estructura jerárquica, vía selector de carpeta o drag & drop.

### Modified Capabilities
- `file-upload-ux`: El flujo de upload ahora distingue entre archivos sueltos y carpetas; el área de drag & drop procesa carpetas correctamente.

## Impact

- **Backend**: `FileController::downloadFolder()` — eliminar `addFolderToZip()` recursivo sobre DB, reemplazar por recorrido del filesystem.
- **Frontend**: `resources/views/files/index.blade.php` — modal de upload, función `uploadFiles()`, handler de drop.
- **Rutas**: Sin cambios — se reutilizan `POST /files` (crear carpeta) y `POST /files/upload` (subir archivo).
- **Modelos**: Sin cambios — `File`, `StorageProvider` no se modifican.
- **Migraciones**: No requeridas.

## Non-goals

- Subida de carpetas en la vista de shares públicos.
- Progreso granular por sub-carpeta (solo por archivo individual).
- Soporte para storages S3 (solo local).
