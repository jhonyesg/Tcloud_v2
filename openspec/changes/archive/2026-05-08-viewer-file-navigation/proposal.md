## Why

Al abrir un archivo en el visor interno, no hay forma de pasar al siguiente o al anterior sin cerrar el visor, volver a la lista y abrir otro. Para carpetas con muchos archivos (fotos, videos) esto interrumpe el flujo de revisión.

## What Changes

- Agregar flechas de navegación (anterior / siguiente) dentro del visor a pantalla completa para moverse entre los archivos visibles en la carpeta actual, sin salir del visor
- Mostrar un indicador de posición (ej. "3 / 12") en el header del visor
- Soportar navegación con teclado (flecha izquierda / derecha)
- Al navegar, resetear las transformaciones de imagen (zoom, rotación, pan) igual que al abrir un archivo nuevo
- Las carpetas se omiten en la navegación (solo archivos que puedan abrirse en el visor)

## Capabilities

### New Capabilities
- `viewer-navigation`: Navegación secuencial entre archivos dentro del visor a pantalla completa usando flechas y teclado.

### Modified Capabilities

## Impact

- Solo `app/resources/views/files/index.blade.php`
- Sin cambios de backend ni rutas
- Sin dependencias nuevas
