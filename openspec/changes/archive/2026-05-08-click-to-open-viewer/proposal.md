## Why

Al hacer click en un archivo en "Mis Archivos", los visores básicos no se abren automáticamente — el usuario tiene que ir al modal de detalle, buscar la sección "Herramientas disponibles" y hacer click en el nombre del visor. La idea es que un click en un archivo con visor compatible lo abra directamente, haciendo la experiencia equivalente a cómo funciona cualquier explorador de archivos.

## What Changes

- Al hacer click en un archivo (no carpeta) en la vista grid: si el archivo tiene un plugin compatible, se lanza el visor directamente en lugar de no hacer nada.
- Al hacer click en un archivo (no carpeta) en la vista lista: misma lógica (actualmente el row click solo navega carpetas).
- Si el archivo no tiene plugin compatible, el click en grid no hace nada (comportamiento actual), y en lista tampoco (o puede abrir el modal de detalle como fallback).
- Los botones de compartir existentes siguen funcionando igual.

## Capabilities

### New Capabilities
- `auto-open-file-viewer`: Al hacer click en un archivo, detectar si tiene plugin compatible y abrirlo directamente sin pasar por el modal de detalle.

### Modified Capabilities
- (ninguna)

## Impact

- `app/resources/views/files/index.blade.php`: modificar el handler `@click` en la tarjeta grid (línea ~580) y la fila de lista (línea ~665) para archivos no-carpeta. Agregar método `openFileViewer(file)` en el Alpine.data component.
- No requiere cambios en backend, rutas, ni modelos.
