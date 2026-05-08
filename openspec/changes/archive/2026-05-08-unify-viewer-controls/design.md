## Context

`shares/public.blade.php` tiene su propio Alpine component (anónimo, con `x-data="{ ... }"` inline) distinto al `Alpine.data('fileManager', ...)` de `files/index.blade.php`. Ambos son independientes — no se puede extraer a un componente compartido sin refactorización mayor.

El visor del share público tiene:
- Un modal `x-show="showModal"` con sidebar de archivos (navegación entre N archivos), área central de contenido y barra inferior con nombre + descarga.
- Para imágenes: `<img :src="...">` estático con `max-h-[85vh]` — sin controles.
- Para video: `<video preload="none">` — no precarga.
- Para audio: funciona, distinto diseño visual pero misma funcionalidad.
- Para PDF: funciona con su propio spinner y botón fullscreen.

El visor interno (`files/index.blade.php`) tiene todos los controles de imagen implementados. La estrategia es copiar exactamente ese patrón al share público, adaptando solo las referencias (`currentFile` en lugar de `currentViewerFile`, `getFileUrl` en lugar de `getViewerUrl`).

## Goals / Non-Goals

**Goals:**
- Estado de imagen idéntico: `imgScale`, `imgRotation`, `imgPanX`, `imgPanY`, `imgDragging` añadidos al Alpine component del share.
- Métodos idénticos: `resetImgTransform()`, `zoomImg(delta)`, `rotateImg(deg)`, `imgViewerStyle()` copiados/adaptados.
- Toolbar de imagen idéntico al del visor interno (rotar izq/der, zoom −/+, porcentaje, reset) posicionado sobre el área de imagen.
- Reset de transformaciones al cambiar de archivo (`setCurrentIndex` ya limpia video/audio/pdf — también debe llamar `resetImgTransform()`).
- `preload="auto"` en el `<video>` del share.

**Non-Goals:**
- Botón "Guardar rotación" — requiere write permission, inapropiado para shares de solo lectura.
- Unificar en un componente Alpine compartido — eso implica refactor de ambas vistas.
- Cambiar el diseño/layout del share público (sidebar, barra inferior, etc.).
- Modificar el PDF viewer del share (ya tiene funcionalidad extra propia).

## Decisions

**Decision 1: Copiar estado y métodos literalmente, ajustar solo nombres de referencia**
`currentViewerFile` → `currentFile`, `getViewerUrl` → `getFileUrl`. El método `imgViewerStyle()` es idéntico. Los 120px de offset en `calc(100vh - 120px)` se mantienen porque el visor del share también tiene header + toolbar de ~120px.

**Decision 2: El toolbar de imagen va DENTRO del área principal del modal, como un overlay sobre la imagen**
En el share público el layout es diferente (sidebar + área central + barra inferior). Para no romper el layout, el toolbar se pone como `div` posicionado `absolute` en la parte superior del área central, visible solo cuando `isImage(currentFile.mime_type)`. El área central ya tiene `@click.stop`, no hay conflicto.

**Decision 3: `preload="auto"` en video, mantener `playsinline`**
`setCurrentIndex` ya hace `v.src = getFileUrl(currentFile); v.load()` en `$nextTick`. Con `preload="auto"` el browser también precarga aunque no se llame `.load()`, siendo más robusto.

## Risks / Trade-offs

- **imgViewerStyle offset de 120px**: El visor del share tiene sidebar (`w-72`) que reduce el ancho disponible pero no el alto. El cálculo `calc(100vh - 120px)` sigue siendo correcto para la altura. El ancho se maneja con `100%` que es relativo al contenedor del área central (ya reducido por el sidebar).
- **Pan puede salirse del área central**: Mismo comportamiento aceptado en el visor interno (comportamiento Picasa).
