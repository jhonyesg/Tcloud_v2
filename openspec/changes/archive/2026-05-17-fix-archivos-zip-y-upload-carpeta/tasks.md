## 1. Backend — Fix descarga ZIP (FileController)

- [ ] 1.1 Eliminar el método privado `addFolderToZip()` de `FileController` (consulta DB, causa ZIP vacío)
- [ ] 1.2 En `downloadFolder()`, obtener la ruta física de la carpeta: `realpath($realBasePath . '/' . $folder->path)`
- [ ] 1.3 Reemplazar la llamada a `addFolderToZip()` por un recorrido con `RecursiveDirectoryIterator` + `RecursiveIteratorIterator` sobre la ruta física
- [ ] 1.4 Por cada archivo encontrado: validar `realpath()` contra `$realBasePath` antes de añadir al ZIP (prevenir path traversal)
- [ ] 1.5 Calcular el `$zipEntry` relativo a la carpeta raíz usando `substr($realFilePath, strlen($folderRealPath) + 1)`
- [ ] 1.6 Mantener el chequeo de 2 GB (`getFolderSizeRecursive`) — actualizar si es necesario para leer tamaño del filesystem en lugar de DB

## 2. Frontend — Función `uploadFolder()` en Alpine.js

- [ ] 2.1 Agregar estado Alpine: `uploadFolderMode: false`, `uploadFolderTotal: 0`, `uploadFolderDone: 0`
- [ ] 2.2 Implementar función `uploadFolder(fileList)` que extrae los `webkitRelativePath` de todos los archivos y construye el set de carpetas únicas ordenadas por profundidad
- [ ] 2.3 En `uploadFolder()`, crear cada carpeta con `POST /files` (con `is_folder: true`, `parent_id`, `storage_id`) en orden padre→hijo, guardando el map `path → id`
- [ ] 2.4 Manejar respuesta 409 en creación de carpeta: hacer `GET /files?parent_id=X&name=Y` para obtener el ID de la carpeta existente y continuar
- [ ] 2.5 Subir cada archivo al `parent_id` correcto usando la función `uploadFile()` existente, pasando `parentId` como parámetro
- [ ] 2.6 Adaptar `uploadFile(file, queueIndex, parentIdOverride)` para aceptar un `parentIdOverride` opcional que reemplace `this.currentFolder`

## 3. Frontend — Modal de upload (Blade)

- [ ] 3.1 En el modal de upload (`resources/views/files/index.blade.php`), agregar botón "Subir carpeta" junto al botón "Seleccionar" existente
- [ ] 3.2 Conectar el botón "Subir carpeta" a un `<input type="file" webkitdirectory class="hidden">` con `@change="uploadFolder($event.target.files)"`
- [ ] 3.3 Agregar el mismo par botón/input en la sección "Agregar más" del modal (cuando ya hay archivos en cola)
- [ ] 3.4 Mostrar en el modal el nombre de carpeta raíz siendo subida y contador `X de Y archivos` cuando `uploadFolderMode` es true

## 4. Frontend — Drag & drop de carpetas (Blade)

- [ ] 4.1 Implementar función `readEntriesRecursive(items)` que usa `item.webkitGetAsEntry()` y `entry.createReader().readEntries()` con loop `while` (hasta array vacío, por límite de 100 entries por llamada)
- [ ] 4.2 La función debe devolver una lista de objetos `{ file: File, relativePath: string }` con `relativePath` simulando `webkitRelativePath`
- [ ] 4.3 Actualizar el handler `@drop` del área principal para detectar si algún item es directorio (`entry.isDirectory`) y llamar `uploadFolder()` en ese caso
- [ ] 4.4 Si la selección contiene mezcla de archivos y carpetas: procesar carpetas con `uploadFolder()` y archivos sueltos con `uploadFiles()` (pueden ejecutarse en paralelo)

## 5. Verificación

- [ ] 5.1 Probar descarga ZIP de carpeta con sub-carpetas no navegadas → ZIP contiene todos los archivos del disco
- [ ] 5.2 Probar descarga ZIP de carpeta vacía → descarga sin error
- [ ] 5.3 Probar subida de carpeta vía selector → estructura y archivos aparecen en el explorador
- [ ] 5.4 Probar subida de carpeta con nombre duplicado → continúa sin error usando carpeta existente
- [ ] 5.5 Probar drag & drop de carpeta → misma verificación que 5.3
- [ ] 5.6 Verificar que upload de archivos individuales (flujo existente) sigue funcionando sin regresiones
- [ ] 5.7 Limpiar caché de vistas: `php artisan view:clear` en el servidor
