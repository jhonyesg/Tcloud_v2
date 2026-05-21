## 1. Backend — FileController::upload()

- [x] 1.1 Después de `$file->move($destDir, $filename)`, leer `filemtime()` con guard `file_exists()` y asignar a `$modifiedAt` como `Carbon::createFromTimestamp()`
- [x] 1.2 Agregar `'file_modified_at' => $modifiedAt` en el array de `File::create()` dentro de `upload()`

## 2. Frontend — Ordenamiento por fecha

- [x] 2.1 En `sortedFiles()` (index.blade.php ~línea 1061), reemplazar `a.file_modified_at || a.created_at || 0` por `a.file_modified_at ? new Date(a.file_modified_at).getTime() : 0` (y lo mismo para `b`)

## 3. Frontend — Display de fecha

- [x] 3.1 En la columna "Fecha" de la tabla de archivos (~línea 2319), cambiar `formatDate(file.file_modified_at || file.created_at)` por expresión condicional que muestre `"—"` si `file_modified_at` es null
- [x] 3.2 En el panel lateral de detalles (~línea 2531), aplicar el mismo cambio para `selectedFile.file_modified_at`
