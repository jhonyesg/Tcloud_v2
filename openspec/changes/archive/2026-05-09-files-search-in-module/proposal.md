## Why

El módulo de archivos no tiene búsqueda funcional: el cuadro que aparece en el navbar es puramente decorativo (sin lógica), por lo que los usuarios no pueden localizar archivos por nombre. Con el crecimiento del contenido almacenado esto se vuelve un bloqueador real de productividad.

## What Changes

- Se elimina el cuadro de búsqueda decorativo del navbar global (`layouts/app.blade.php`).
- Se añade un cuadro de búsqueda funcional dentro del módulo de archivos, visible en la barra de herramientas de la vista de archivos.
- La búsqueda envía el término al backend (`GET /files?q=...`) y devuelve resultados de todos los archivos (y carpetas) del storage activo que coincidan con el nombre, ignorando la carpeta actual (búsqueda global dentro del storage).
- El backend (`FileController::index`) acepta el parámetro `q` y filtra por `name LIKE %q%`, restringido al storage activo del usuario.
- Los resultados se presentan en modo plano (sin jerarquía de carpetas) con un indicador de que se está en modo búsqueda.
- Al limpiar la búsqueda se vuelve a la vista normal de carpetas.

## Capabilities

### New Capabilities
- `files-search`: Búsqueda de archivos y carpetas por nombre dentro del storage activo, con soporte en backend via parámetro `q` y UI integrada en el módulo de archivos.

### Modified Capabilities
<!-- Sin specs existentes que cambien en requisitos. -->

## Impact

- `app/resources/views/layouts/app.blade.php`: Se elimina el bloque del input de búsqueda decorativo del header.
- `app/resources/views/files/index.blade.php`: Se añade el input de búsqueda con Alpine.js (debounce, estado, limpiar).
- `app/app/Http/Controllers/FileController.php`: Se añade soporte para el parámetro `q` en el método `index()`.
- Sin nuevas rutas, sin nuevas dependencias.
