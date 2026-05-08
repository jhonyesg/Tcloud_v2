## Context

`fileManager` en `files/index.blade.php` es un Alpine.js component. El sistema de subida actual tiene un modal que incluye una zona drag-and-drop, pero:
1. No hay zona de drop en la vista principal — el usuario tiene que abrir el modal antes de arrastrar
2. `uploadFile()` no tiene loading state ni manejo de errores visible — el fetch puede fallar silenciosamente (HTTP 409, 413, 403, 422) y el usuario no recibe ninguna señal
3. Solo sube un archivo a la vez y no muestra progreso
4. El visor de imágenes es solo un `<img>` estático dentro del modal del viewer

## Goals / Non-Goals

**Goals:**
- Zona de drag-and-drop en la vista principal de archivos (overlay al arrastrar) que llame `uploadFile()` directamente sin abrir el modal
- Subida multi-archivo: cuando se arrastran varios archivos se procesan secuencialmente
- Feedback de progreso por archivo (usar `XMLHttpRequest` con evento `progress` en lugar de `fetch`)
- Mensajes de error específicos según código HTTP (409 = duplicado, 413 = cuota excedida, 403 = sin permisos, 422 = storage no seleccionado)
- Controles de imagen en el viewer: zoom con rueda del ratón y botones ±, rotación en pasos de 90° (izquierda/derecha), reset, pan con arrastre cuando escala > 1

**Non-Goals:**
- Upload de carpetas o directorios completos
- Barra de progreso por bytes a nivel global (solo por archivo individual)
- Zoom libre con pinch-to-zoom táctil (solo rueda + botones)
- Undo/redo de rotación persistente en servidor

## Decisions

**Decision 1: Overlay drag-and-drop en la vista principal con estado Alpine**
Agregar `dragOverMain: false` al estado del component. Escuchar `@dragenter.prevent` / `@dragleave.prevent` / `@drop.prevent` en el contenedor principal del panel de archivos. Al soltar, procesar `$event.dataTransfer.files` en loop. El overlay se muestra con `x-show="dragOverMain"` y posición `absolute` dentro del panel, no `fixed` (evita conflictos con otros modales).

Alternativa descartada: overlay `fixed` sobre toda la pantalla — interfiere con el cierre de otros modales activos.

**Decision 2: XMLHttpRequest en lugar de fetch para progreso**
`fetch` API no expone progreso de subida. Se reemplaza `uploadFile()` por una versión basada en `XMLHttpRequest` que escucha `xhr.upload.onprogress`. El progreso se guarda en un array `uploadQueue: []` con `{ name, progress, error, done }` y se muestra en el modal.

Alternativa descartada: mantener `fetch` y simular progreso — no refleja el progreso real y confunde al usuario.

**Decision 3: Procesar multi-archivo secuencialmente**
Al arrastrar N archivos se crea N entradas en `uploadQueue` y se suben uno a uno con `await` (no en paralelo). Esto evita saturar el servidor y hace más predecible el feedback.

Alternativa descartada: subida en paralelo — puede saturar PHP-FPM con archivos grandes.

**Decision 4: Controles de imagen con CSS transform en Alpine state**
Agregar `imgScale`, `imgRotation`, `imgPanX`, `imgPanY`, `imgDragging` al estado del viewer. Aplicar via `:style` en el `<img>`. Pan implementado con `mousedown` / `mousemove` / `mouseup` directamente en el elemento imagen. Rueda del ratón capturada con `@wheel.prevent`.

Sin dependencias externas — todo CSS transform nativo.

**Decision 5: Reset de transformaciones al abrir nueva imagen**
En `openViewer()`, resetear `imgScale = 1`, `imgRotation = 0`, `imgPanX = 0`, `imgPanY = 0` antes de mostrar la nueva imagen. Así cada imagen empieza en estado neutro.

## Risks / Trade-offs

- **dragLeave falso positivo**: Al arrastrar sobre elementos hijos del contenedor se dispara `dragleave` del padre. Mitigación: usar un contador `dragDepth` en lugar de un boolean — incrementar en `dragenter`, decrementar en `dragleave`, ocultar overlay solo cuando llega a 0.
- **Pan fuera de límites**: No hay límite de pan — el usuario puede desplazar la imagen completamente fuera de la vista. Aceptable para MVP; es comportamiento conocido en Picasa.
- **Subida sin storage seleccionado**: Si el usuario no está dentro de un storage y arrastra, el `storage_id` estará vacío y el backend devolverá 422. El frontend mostrará "Debes estar dentro de un storage para subir archivos" al detectar `currentStorage` nulo antes de enviar.
