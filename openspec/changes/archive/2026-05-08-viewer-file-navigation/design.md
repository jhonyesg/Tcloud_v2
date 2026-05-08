## Context

El visor interno usa `currentViewerFile` para saber qué archivo mostrar. La lista de archivos disponible está en `this.files` (array reactivo de Alpine), que el método `sortedFiles()` ordena. Al abrir el visor, no se guarda ningún índice — se desconoce la posición del archivo dentro de la lista.

Para navegar necesitamos saber: (1) cuál es la lista navegable, (2) dónde está el archivo actual en esa lista.

## Goals / Non-Goals

**Goals:**
- Nuevo estado `viewerIndex: null` — índice del archivo actual dentro de la lista navegable.
- Lista navegable = `sortedFiles()` filtrada para excluir carpetas (`!f.is_folder`).
- `openViewer(file)` busca el índice del archivo en la lista navegable y lo guarda en `viewerIndex`.
- Métodos `viewerNext()` y `viewerPrev()` avanzan/retroceden en la lista y abren el archivo correspondiente.
- Flechas `<` y `>` en el visor (superpuestas a los lados del área de contenido), visibles siempre que haya archivo anterior/siguiente.
- Indicador `X / N` en el header junto al nombre del archivo.
- `@keydown.arrowleft.window` / `@keydown.arrowright.window` llaman `viewerPrev()` / `viewerNext()` cuando el visor está abierto.

**Non-Goals:**
- Preload del siguiente archivo.
- Miniaturas en barra inferior (scope de share público, no del visor interno).
- Persistir el índice en `localStorage`.

## Decisions

**Decision 1: Lista navegable computada al abrir el visor, no reactiva**
`sortedFiles()` es un método (no computed), por lo que cambia si el orden cambia. Para evitar que el índice quede desfasado, la lista navegable se captura en el momento de `openViewer()` y se guarda en `viewerFiles: []`. Así la navegación es estable durante toda la sesión del visor, aunque el usuario haya cambiado el sort mientras el visor estaba abierto.

**Decision 2: Las flechas se ponen como overlays absolutos en los laterales del área de contenido**
El área de contenido del visor es `flex-1 overflow-hidden`. Los botones de flecha se colocan como `position: absolute` izquierda/derecha centrados verticalmente, con z-index suficiente para estar sobre el contenido pero sin bloquear los controles del video/audio. `pointer-events: none` en el contenedor de flechas si está invisible, `pointer-events: auto` solo en los botones activos.

**Decision 3: `viewerNext()` y `viewerPrev()` reutilizan `openViewer()`**
Para no duplicar la lógica de limpiar video/audio y resetear transformaciones, `viewerNext/Prev` simplemente llaman `openViewer(viewerFiles[newIndex])`. `openViewer` ya llama `resetImgTransform()` y gestiona los refs de video/audio.

## Risks / Trade-offs

- **Lista stale**: Si se sube un archivo mientras el visor está abierto, `viewerFiles` no lo incluirá. Aceptable — la navegación es sobre la lista que existía al abrir.
- **Índice incorrecto si el archivo no está en `sortedFiles()`**: puede ocurrir en edge cases con filtros futuros. Se maneja con `viewerIndex = -1` cuando `findIndex` no encuentra el archivo, en cuyo caso las flechas se ocultan.
