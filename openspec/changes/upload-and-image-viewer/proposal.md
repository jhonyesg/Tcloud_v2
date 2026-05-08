## Why

Al arrastrar archivos sobre el módulo "Mis Archivos" no ocurre nada — no hay zona de drop en la vista principal, ni feedback visual de progreso ni errores. Adicionalmente, el visor de imágenes solo muestra la imagen sin controles, lo que impide al usuario rotar o hacer zoom a una foto sin descargarla.

## What Changes

- Agregar zona de drag-and-drop directamente en la vista de archivos (sin tener que abrir el modal de subida)
- Mostrar progreso de subida (barra o spinner) y mensajes de error claros (cuota excedida, archivo duplicado, sin permisos)
- Soporte para subir múltiples archivos en una sola operación drag-and-drop
- Reemplazar el `<img>` simple del visor por un visor interactivo con zoom (rueda del ratón + botones), rotación (90° izquierda/derecha), reset y arrastre para desplazamiento cuando la imagen está ampliada

## Capabilities

### New Capabilities
- `file-upload-ux`: Subida de archivos con feedback visual completo — zona de drop en vista principal, barra de progreso por archivo, mensajes de error específicos, soporte multi-archivo.
- `image-viewer-controls`: Controles interactivos en el visor de imágenes — zoom in/out con rueda del ratón y botones, rotación en pasos de 90°, reset, pan con arrastre al estar ampliado.

### Modified Capabilities

## Impact

- Solo `app/resources/views/files/index.blade.php` — cambios puramente en Alpine.js y HTML
- Sin cambios de backend, rutas ni base de datos
- Sin dependencias nuevas — CSS `transform` + eventos del navegador nativos
