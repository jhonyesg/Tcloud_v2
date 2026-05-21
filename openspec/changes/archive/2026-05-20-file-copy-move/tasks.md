## 1. Backend — Rutas

- [x] 1.1 Agregar `Route::post('/files/{file}/copy', [FileController::class, 'copy'])` en `routes/web.php`
- [x] 1.2 Agregar `Route::post('/files/{file}/move', [FileController::class, 'move'])` en `routes/web.php`

## 2. Backend — FileController: método copy()

- [x] 2.1 Crear método `copy(Request $request, int $id)`: validar permiso `full`, resolver paths, verificar que no existe conflicto de nombre en destino (409)
- [x] 2.2 Implementar copia de archivo individual: `copy($srcPath, $dstPath)` en disco + `File::create([..., 'file_modified_at' => filemtime(...)])` en BD
- [x] 2.3 Implementar copia recursiva de carpeta: método privado `copyFolderRecursively(File $src, string $srcPhysicalBase, string $dstPhysicalBase, int $dstParentId, StorageProvider $storage)` que: (1) crea el directorio en disco con `mkdir($dstPhysicalBase, 0755, true)`, (2) crea el registro de carpeta en BD, (3) recorre los hijos: para archivos llama `copy($srcFilePath, $dstFilePath)` en disco + `File::create()` en BD; para subcarpetas se llama recursivamente

## 3. Backend — FileController: método move()

- [x] 3.1 Crear método `move(Request $request, int $id)`: validar permiso `full`, validar que destino no es descendiente del origen (422), verificar conflicto de nombre (409)
- [x] 3.2 Implementar movimiento de archivo individual: `rename($srcPath, $dstPath)` en disco + actualizar `path` y `parent_id` en BD
- [x] 3.3 Implementar movimiento de carpeta: `rename($srcDir, $dstDir)` en disco + `UPDATE files SET path = REPLACE(path, old_prefix, new_prefix) WHERE storage_provider_id = ? AND path LIKE 'old_prefix%'` para todos los descendientes + actualizar `path` y `parent_id` de la carpeta raíz

## 4. Frontend — Estado Alpine y helper

- [x] 4.1 Agregar estado en `index.blade.php`: `showCopyMoveModal: false`, `copyMoveAction: null`, `copyMoveSourceFile: null`, `copyMoveDestFolderId: null`, `copyMoveDestLabel: 'Raíz'`, `copyMoveFolders: []`, `copyMoveLoading: false`, `copyMoveError: null`
- [x] 4.2 Agregar helper `canCopyMove()` que retorna `this.currentStoragePermission === 'full'`
- [x] 4.3 Agregar método `openCopyMoveModal(file, action)` que inicializa el estado y carga las carpetas de la raíz del storage actual via fetch
- [x] 4.4 Agregar método `confirmCopyMove()` que llama `POST /files/{id}/copy` o `POST /files/{id}/move` con `destination_parent_id`, maneja error y refresca la lista al completar

## 5. Frontend — Modal de destino

- [x] 5.1 Crear modal `x-show="showCopyMoveModal"` en `index.blade.php` con título dinámico ("Copiar a..." / "Mover a..."), breadcrumb de navegación, lista de carpetas del nivel actual con opción de entrar en cada una, y botón "Seleccionar aquí"
- [x] 5.2 Agregar navegación en el modal: click en carpeta carga sus subcarpetas y actualiza `copyMoveDestFolderId` y el breadcrumb
- [x] 5.3 Mostrar mensaje de error `copyMoveError` dentro del modal si la operación falla
- [x] 5.4 Agregar estado de loading en el botón de confirmación mientras se ejecuta la operación

## 6. Frontend — Botones en grilla y tabla

- [x] 6.1 En la vista **grilla** (`x-for="file in sortedFiles()"`): agregar botones "Copiar" y "Mover" con `x-show="canCopyMove()"`, junto a los botones existentes de renombrar y eliminar
- [x] 6.2 En la vista **tabla**: agregar los mismos botones con `x-show="canCopyMove()"` en la columna de acciones
