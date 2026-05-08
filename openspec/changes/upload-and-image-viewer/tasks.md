## 1. Refactorizar uploadFile() con XMLHttpRequest y progreso

- [x] 1.1 Agregar estado `uploadQueue: []` al Alpine component (array de `{ name, progress, error, done }`)
- [x] 1.2 Reemplazar `uploadFile(file)` por versión basada en `XMLHttpRequest` con `xhr.upload.onprogress` actualizando `uploadQueue[i].progress`
- [x] 1.3 Mapear códigos HTTP de error a mensajes legibles: 409 → "Ya existe un archivo con ese nombre", 413 → "Cuota excedida", 403 → "Sin permisos de escritura", 422 → "Debes estar dentro de un storage para subir"
- [x] 1.4 Al completar cada archivo exitosamente marcar `done: true` en la entrada de `uploadQueue` y llamar `loadFiles()`

## 2. Soporte multi-archivo y procesamiento secuencial

- [x] 2.1 Agregar método `uploadFiles(fileList)` que crea las entradas en `uploadQueue` y llama `uploadFile()` en un loop con `await` secuencial
- [x] 2.2 Actualizar la zona drop del modal (`@drop`) para llamar `uploadFiles($event.dataTransfer.files)` en lugar de `uploadFile(files[0])`
- [x] 2.3 Actualizar el `<input type="file">` del modal para agregar `multiple` y llamar `uploadFiles($event.target.files)`

## 3. Zona drag-and-drop en la vista principal

- [x] 3.1 Agregar estado `dragOverMain: false` y `dragDepth: 0` al Alpine component
- [x] 3.2 Agregar `@dragenter.prevent` (incrementa `dragDepth`, activa overlay), `@dragleave.prevent` (decrementa `dragDepth`, desactiva si llega a 0) y `@drop.prevent` (desactiva overlay, llama `uploadFiles`) en el contenedor principal del panel
- [x] 3.3 Agregar overlay HTML con `x-show="dragOverMain && viewMode === 'files'"` sobre el panel de archivos (posición `absolute`, fondo semitransparente, texto "Suelta para subir")
- [x] 3.4 Validar `currentStorage` antes de iniciar subida — si es nulo, mostrar error en `uploadQueue` y no enviar

## 4. UI del modal de subida con cola de progreso

- [x] 4.1 Reemplazar el contenido del modal `showUploadModal` para mostrar `uploadQueue` con `x-for` — nombre de archivo, barra de progreso (`<progress>` o div con `width` dinámica) y mensaje de error/éxito
- [x] 4.2 El modal se abre automáticamente al iniciar la subida (`showUploadModal = true`) y muestra un botón "Cerrar" que solo está activo cuando todos los archivos terminaron (`uploadQueue.every(f => f.done || f.error)`)
- [x] 4.3 Limpiar `uploadQueue` al abrir el modal manualmente (botón "Subir Archivo" del toolbar)

## 5. Estado de controles de imagen en el viewer

- [x] 5.1 Agregar `imgScale: 1`, `imgRotation: 0`, `imgPanX: 0`, `imgPanY: 0`, `imgDragging: false`, `imgDragStart: { x: 0, y: 0 }` al Alpine component
- [x] 5.2 Agregar método `resetImgTransform()` que resetea todos esos valores a su estado inicial
- [x] 5.3 Llamar `resetImgTransform()` al inicio de `openViewer()` antes de mostrar la nueva imagen

## 6. Controles de imagen — zoom y rotación

- [x] 6.1 Agregar método `zoomImg(delta)` que modifica `imgScale` sumando `delta` con clamp entre 0.2 y 5
- [x] 6.2 Agregar método `rotateImg(deg)` que suma `deg` a `imgRotation` (acepta ±90)
- [x] 6.3 En el `<img>` del viewer, agregar `:style` que aplica `transform: scale(imgScale) rotate(imgRotation + 'deg') translate(imgPanX + 'px', imgPanY + 'px')` y `@wheel.prevent="zoomImg($event.deltaY < 0 ? 0.2 : -0.2)"`

## 7. Controles de imagen — barra de botones

- [x] 7.1 Agregar barra de botones de imagen debajo del header del viewer, visible solo cuando `isImage(currentViewerFile?.mime_type)`: botones Rotar Izq, Rotar Der, Zoom −, Zoom +, Reset
- [x] 7.2 Conectar cada botón: Rotar Izq → `rotateImg(-90)`, Rotar Der → `rotateImg(90)`, Zoom − → `zoomImg(-0.25)`, Zoom + → `zoomImg(0.25)`, Reset → `resetImgTransform()`

## 8. Controles de imagen — pan con arrastre

- [x] 8.1 En el `<img>` del viewer, agregar `@mousedown` que activa `imgDragging = true` y guarda posición inicial en `imgDragStart` (solo si `imgScale > 1`)
- [x] 8.2 Agregar `@mousemove` en el contenedor del viewer que actualiza `imgPanX` e `imgPanY` mientras `imgDragging` es true
- [x] 8.3 Agregar `@mouseup` y `@mouseleave` en el contenedor que desactivan `imgDragging`
- [x] 8.4 Aplicar `:class` en el `<img>` para cambiar cursor: `cursor-grab` cuando `imgScale > 1` y no arrastrando, `cursor-grabbing` cuando arrastrando
