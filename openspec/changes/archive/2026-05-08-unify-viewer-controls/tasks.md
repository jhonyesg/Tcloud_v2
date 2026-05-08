## 1. Agregar estado de imagen al Alpine component del share público

- [x] 1.1 Localizar el bloque `x-data="{ ... }"` del Alpine component principal en `shares/public.blade.php` y agregar: `imgScale: 1, imgRotation: 0, imgPanX: 0, imgPanY: 0, imgDragging: false`

## 2. Agregar métodos de imagen al Alpine component del share público

- [x] 2.1 Agregar `resetImgTransform()` que resetea todos los valores a su estado inicial (idéntico al del visor interno)
- [x] 2.2 Agregar `zoomImg(delta)` con clamp entre 0.2 y 5 (idéntico al del visor interno)
- [x] 2.3 Agregar `rotateImg(deg)` que acumula la rotación (idéntico al del visor interno)
- [x] 2.4 Agregar `imgViewerStyle()` que computa el `:style` con transform y max-w/max-h intercambiados según si la imagen está transpuesta (copia exacta del visor interno usando `currentFile` en lugar de `currentViewerFile`)

## 3. Resetear transformaciones al cambiar de archivo

- [x] 3.1 En el método `setCurrentIndex()` del share público, agregar llamada a `this.resetImgTransform()` al inicio del método (antes de hacer pause/clear del video/audio)

## 4. Reemplazar el `<img>` estático por el visor interactivo

- [x] 4.1 En el bloque de imagen del modal (`x-show="currentFile && isImage(currentFile.mime_type)"`), reemplazar el `<img>` estático por uno con `:style="imgViewerStyle()"`, `@wheel.prevent`, `@mousedown.prevent` y `:class` para cursor grab/grabbing (igual que en el visor interno)
- [x] 4.2 Cambiar el `div` contenedor de la imagen de `class="flex items-center justify-center h-full"` a `class="w-full h-full flex items-center justify-center overflow-hidden"` con handlers `@mousemove`, `@mouseup`, `@mouseleave` para el pan (igual que el contenedor del visor interno)

## 5. Agregar toolbar de controles de imagen

- [x] 5.1 Agregar el toolbar de imagen (idéntico al del visor interno) dentro del área central del modal del share público, visible solo cuando `currentFile && isImage(currentFile.mime_type)`. Posicionarlo como `div` hermano del bloque de imagen, antes del bloque de video — sin botón "Guardar rotación".

## 6. Corregir preload de video

- [x] 6.1 Cambiar `preload="none"` a `preload="auto"` en el `<video x-ref="videoplayer">` del share público
