## Why

Actualmente el usuario debe descargar archivos de uno en uno. No existe modo de selección múltiple ni acción de descarga masiva. Esto obliga a N descargas manuales cuando se necesita llevarse varios archivos, lo que resulta frustrante especialmente en carpetas con muchos elementos.

## What Changes

- **Selección múltiple**: Checkboxes en cada archivo/carpeta (visibles al hover en grid, siempre visibles en lista). Ctrl+Click también añade a la selección. Click normal en el fondo deselecciona todo.
- **Barra de acciones flotante**: Cuando hay 1+ elemento seleccionado aparece una barra en la parte superior del área de archivos con el contador y el botón "Descargar X elementos".
- **Endpoint de descarga múltiple**: `POST /files/download-multi` recibe un array de IDs, valida permisos, y devuelve un ZIP con todos los archivos y el contenido de las carpetas seleccionadas.
- **"Seleccionar todo"**: Checkbox en el header de la vista lista que selecciona/deselecciona todos los archivos visibles.

## Capabilities

### New Capabilities
- `multi-select-files`: Selección múltiple de archivos y carpetas con checkboxes, Ctrl+Click, y selección total.
- `bulk-download`: Descarga de varios archivos/carpetas seleccionados como un único ZIP desde un nuevo endpoint del backend.

### Modified Capabilities
_(ninguna — la lógica de selección existente `selectedFiles: []` estaba declarada pero sin implementar)_

## Impact

- **Backend**: Nuevo método `downloadMulti()` en `FileController` + nueva ruta `POST /files/download-multi`.
- **Frontend**: `resources/views/files/index.blade.php` — checkboxes en grid y lista, barra de acciones, función `toggleSelect()`, `selectAll()`, `downloadSelected()`.
- **Rutas**: `app/routes/web.php` — agregar ruta `POST /files/download-multi`.
- **Modelos**: Sin cambios.
- **Migraciones**: No requeridas.

## Non-goals

- Acciones masivas distintas a descarga (borrado múltiple, mover, copiar).
- Selección múltiple en shares públicos.
- Descarga múltiple en storages S3 (solo local).
- Persistencia de la selección entre navegaciones de carpeta.
