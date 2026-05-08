## Why

Al recargar la página en el módulo "Mis Archivos", el estado de navegación (storage actual, carpeta actual y breadcrumbs) se pierde y el usuario vuelve a la raíz, teniendo que navegar de nuevo hasta su ubicación. Para un sistema de archivos con carpetas anidadas esto interrumpe el flujo de trabajo.

## What Changes

- Serializar el estado de navegación actual (`currentStorage`, `currentStorageName`, `currentFolder`, `currentFolderName`, `breadcrumbs`, `viewMode`) en `localStorage` cada vez que cambia
- Al inicializar `fileManager`, leer ese estado guardado y restaurarlo antes de cargar los archivos
- Si el estado guardado contiene un storage/carpeta que ya no existe o no es accesible, caer silenciosamente a la raíz
- Limpiar el estado guardado cuando el usuario navega explícitamente a la raíz

## Capabilities

### New Capabilities
- `navigation-state-persistence`: Guardar y restaurar la posición de navegación del módulo de archivos entre recargas de página usando `localStorage`.

### Modified Capabilities

## Impact

- Solo `app/resources/views/files/index.blade.php` (el Alpine component `fileManager`)
- Sin cambios de backend, rutas ni base de datos
- Sin dependencias nuevas — `localStorage` es nativo del browser
