## Why

El visor interno de "Mis Archivos" tiene zoom, rotación, pan y guardar rotación para imágenes, y video con preload automático. El visor de shares públicos muestra las imágenes como `<img>` sin ningún control, y el video no precarga. Un usuario que abre un archivo compartido por link recibe una experiencia degradada respecto a quien lo abre desde el módulo interno.

## What Changes

- Agregar a `shares/public.blade.php` los mismos controles de imagen que existen en `files/index.blade.php`: zoom (rueda del ratón + botones ±), rotación 90° izquierda/derecha, reset, y pan arrastrando cuando scale > 1
- Agregar estado Alpine y métodos equivalentes (`imgScale`, `imgRotation`, `imgPanX`, `imgPanY`, `imgDragging`, `resetImgTransform`, `zoomImg`, `rotateImg`, `imgViewerStyle`) al component del share público
- Resetear las transformaciones al cambiar de archivo (`setCurrentIndex`)
- Cambiar `preload="none"` a `preload="auto"` en el `<video>` del share público para que empiece a cargar al abrirse, igual que en el visor interno
- **No** incluir el botón "Guardar rotación" en el share público (requiere permisos de escritura que los shares no tienen)

## Capabilities

### New Capabilities

### Modified Capabilities
- `image-viewer-controls`: Extender los controles de imagen (zoom, rotate, pan) al visor de shares públicos, igualando la experiencia con el visor interno.

## Impact

- Solo `app/resources/views/shares/public.blade.php`
- Sin cambios de backend ni rutas
- Sin dependencias nuevas — reutiliza exactamente el mismo patrón Alpine.js ya implementado en `files/index.blade.php`
