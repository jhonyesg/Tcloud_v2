## 1. Agregar estado de navegación al Alpine component

- [x] 1.1 Agregar `viewerIndex: null` y `viewerFiles: []` al objeto de estado del Alpine.data('fileManager') en `files/index.blade.php`

## 2. Actualizar `openViewer()` para capturar posición

- [x] 2.1 Al inicio de `openViewer(file)`, calcular la lista navegable: `this.viewerFiles = this.sortedFiles().filter(f => !f.is_folder)`
- [x] 2.2 Buscar el índice del archivo recibido: `this.viewerIndex = this.viewerFiles.findIndex(f => f.id === file.id)`

## 3. Agregar métodos `viewerNext()` y `viewerPrev()`

- [x] 3.1 Agregar `viewerNext()`: si `viewerIndex < viewerFiles.length - 1`, incrementar `viewerIndex` y llamar `openViewer(viewerFiles[viewerIndex])`
- [x] 3.2 Agregar `viewerPrev()`: si `viewerIndex > 0`, decrementar `viewerIndex` y llamar `openViewer(viewerFiles[viewerIndex])`

## 4. Agregar botones de flecha en el visor

- [x] 4.1 Dentro del área de contenido del visor (`flex-1 overflow-hidden`), agregar un contenedor `relative` (o aplicar `relative` al existente) que envuelva el contenido
- [x] 4.2 Agregar botón `<` como overlay absoluto en el lado izquierdo: `position: absolute`, centrado verticalmente, `x-show="viewerIndex > 0"`, `@click="viewerPrev()"`
- [x] 4.3 Agregar botón `>` como overlay absoluto en el lado derecho: `position: absolute`, centrado verticalmente, `x-show="viewerIndex < viewerFiles.length - 1"`, `@click="viewerNext()"`

## 5. Agregar indicador de posición en el header del visor

- [x] 5.1 En el header del visor, junto al nombre del archivo, agregar un `<span>` con `x-show="viewerFiles.length > 1"` que muestre `(viewerIndex + 1) + ' / ' + viewerFiles.length`

## 6. Agregar navegación por teclado

- [x] 6.1 En el elemento raíz del visor (el `div` con `x-show="viewerOpen"`), agregar `@keydown.arrowleft.window="viewerOpen && viewerPrev()"` y `@keydown.arrowright.window="viewerOpen && viewerNext()"`
