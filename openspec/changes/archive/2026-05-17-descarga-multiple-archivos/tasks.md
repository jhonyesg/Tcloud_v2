## 1. Backend — Endpoint descarga múltiple (FileController)

- [x] 1.1 Extraer la lógica de recorrido ZIP a un método privado reutilizable `addPathToZip(\ZipArchive $zip, string $realPath, string $realBasePath, string $zipPrefix)` que sirva tanto a `downloadFolder` como al nuevo endpoint
- [x] 1.2 Crear método `downloadMulti(Request $request)` en `FileController` con validación: `ids` requerido, array, mínimo 1 elemento, cada ID es entero
- [x] 1.3 En `downloadMulti()`, verificar existencia de cada File (404 si alguno no existe) y permiso `read` (403 si falla alguno)
- [x] 1.4 Calcular tamaño total: archivos sueltos con `$file->size`, carpetas con `getFolderSizeRecursive()`; rechazar con 413 si supera 2 GB
- [x] 1.5 Generar el ZIP: archivos sueltos en raíz (con detección de nombre duplicado y sufijo `_N`), carpetas como subdirectorio con `addPathToZip()`
- [x] 1.6 Retornar `response()->download($tmpFile, 'descarga.zip', [...])->deleteFileAfterSend(true)`

## 2. Backend — Ruta

- [x] 2.1 Añadir en `routes/web.php` dentro del grupo auth: `Route::post('/files/download-multi', [FileController::class, 'downloadMulti'])`

## 3. Frontend — Estado Alpine y helpers de selección

- [x] 3.1 Verificar que `selectedFiles: []` existe (línea 28) — ya está declarado, sin cambios
- [x] 3.2 Agregar computed/helpers Alpine: `isSelected(file)`, `toggleSelect(file)`, `clearSelection()`, getter `hasSelection` (computed como función)
- [x] 3.3 Agregar función `selectAll()` que hace `selectedFiles = [...files]`
- [x] 3.4 Limpiar `selectedFiles` en `navigateToFolder()` y en `loadFiles()` para no mantener selección obsoleta

## 4. Frontend — Checkboxes en vista grid (Blade)

- [x] 4.1 En cada tarjeta de archivo de la vista grid, añadir un `<div>` con checkbox posicionado absolutamente en la esquina superior izquierda, visible solo con `group-hover` o cuando `isSelected(file)`
- [x] 4.2 Conectar el checkbox con `@click.stop="toggleSelect(file)"` para no propagar al click de navegación
- [x] 4.3 Aplicar clase de fondo de selección a la tarjeta completa: `:class="isSelected(file) ? 'ring-2 ring-blue-500 bg-blue-50' : ''"`
- [x] 4.4 Añadir `@click.ctrl.prevent.stop="toggleSelect(file)"` en la tarjeta para Ctrl+Click

## 5. Frontend — Checkboxes en vista lista (Blade)

- [x] 5.1 Agregar columna checkbox al inicio del `<thead>` de la tabla lista con el checkbox de "seleccionar todo" (`@click="selectAll()"` / `@click="clearSelection()"`)
- [x] 5.2 En cada `<tr>` de archivo, añadir `<td>` con checkbox `@click.stop="toggleSelect(file)"` siempre visible
- [x] 5.3 Aplicar resaltado de fila seleccionada: `:class="isSelected(file) ? 'bg-blue-50' : 'hover:bg-slate-50'"`
- [x] 5.4 El checkbox de cabecera debe reflejar estado: marcado si todos seleccionados, indeterminado si algunos, vacío si ninguno

## 6. Frontend — Click en fondo limpia la selección (Blade)

- [x] 6.1 En el contenedor principal del área de archivos (grid/lista), añadir `@click.self="clearSelection()"` en el elemento padre
- [x] 6.2 Asegurar que los clicks dentro de tarjetas tienen `.stop` para no propagar al padre y activar `clearSelection()` accidentalmente

## 7. Frontend — Barra de acciones flotante (Blade)

- [x] 7.1 Añadir `<div x-show="hasSelection()">` encima del grid/lista con la barra de acciones (fondo blanco/sombra, aparición con `x-transition`)
- [x] 7.2 Mostrar contador: `<span x-text="selectedFiles.length + ' elemento' + (selectedFiles.length !== 1 ? 's' : '') + ' seleccionado' + (selectedFiles.length !== 1 ? 's' : '')"></span>`
- [x] 7.3 Botón "✕ Limpiar" que llama `clearSelection()`
- [x] 7.4 Botón "⬇ Descargar ZIP" que llama `downloadSelected()`

## 8. Frontend — Función `downloadSelected()` (Alpine)

- [x] 8.1 Implementar `async downloadSelected()`: calcular tamaño total en cliente (suma `selectedFiles.map(f => f.size)` para archivos, usar `getFolderSizeRecursive` vía llamada al backend si hay carpetas, o simplemente dejar que el backend rechace si excede)
- [x] 8.2 Hacer `POST /files/download-multi` con `{ ids: selectedFiles.map(f => f.id) }` incluyendo CSRF token
- [x] 8.3 Si respuesta ok: crear un `<a>` temporal con blob URL y simular click para descargar el ZIP
- [x] 8.4 Si respuesta error: leer JSON y mostrar `showToast(data.error || 'Error al descargar')`
- [x] 8.5 Mostrar estado de carga en el botón durante la generación del ZIP (`downloadingMulti: false` como estado Alpine)

## 9. Verificación

- [ ] 9.1 Seleccionar 3 archivos → barra aparece con contador correcto → "Descargar ZIP" genera ZIP con los 3 archivos
- [ ] 9.2 Seleccionar archivos + carpeta → ZIP contiene archivos en raíz y carpeta como subdirectorio
- [ ] 9.3 Dos archivos con el mismo nombre → ZIP los incluye ambos con sufijo `_2`
- [ ] 9.4 Ctrl+Click acumula selección; click normal en nombre navega/abre sin afectar selección
- [ ] 9.5 Click en fondo limpia selección y oculta barra
- [ ] 9.6 Checkbox de cabecera en lista selecciona/deselecciona todos
- [ ] 9.7 Selección > 2 GB → toast de error, sin descarga
- [ ] 9.8 Upload individual y navegación de carpetas siguen funcionando sin regresiones
