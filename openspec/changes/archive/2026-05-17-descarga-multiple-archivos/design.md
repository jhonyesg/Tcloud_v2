## Context

El estado Alpine `selectedFiles: []` está declarado en `files/index.blade.php` línea 28 pero nunca se usa. La selección actual es unitaria (`selectedFile: null`) y solo sirve para abrir el panel de detalle. Los botones de acción (descargar, eliminar, renombrar) están inline en cada tarjeta de archivo, no hay barra de acciones masivas.

El backend tiene `downloadFolder()` que ya genera ZIPs desde el filesystem directo (tras el fix del change `fix-archivos-zip-y-upload-carpeta`). La lógica de ZIP se puede reutilizar.

## Goals / Non-Goals

**Goals:**
- Selección de N archivos/carpetas con checkboxes + Ctrl+Click.
- Barra de acciones que aparece/desaparece según la selección.
- Un único endpoint que genera un ZIP con todo lo seleccionado.
- Sin regresiones en la selección unitaria ni en la navegación.

**Non-Goals:**
- Borrado masivo, mover masivo.
- Selección en shares públicos o storages S3.

## Decisions

### D1 — Selección: modo toggle vs checkboxes siempre visibles

**Opción A (elegida):** Checkboxes visibles al hover (grid) y siempre visibles (lista). Click en el checkbox añade a `selectedFiles`; click en el nombre/icono navega o abre visor como antes.

**Opción B:** Botón "Modo selección" en toolbar que activa los checkboxes globalmente. Más explícito pero añade un paso extra.

**Rationale:** A es más fluido — el usuario puede seleccionar sin cambiar de modo. El comportamiento de click diferenciado (checkbox vs área de nombre) es familiar en exploradores de archivos modernos.

```
INTERACCIÓN DE SELECCIÓN
══════════════════════════════════════════════════
 Click en checkbox  →  toggle en selectedFiles[]
 Click en nombre    →  navegar/abrir visor (sin cambios)
 Ctrl+Click card    →  toggle en selectedFiles[]
 Click en vacío     →  limpiar selectedFiles[]
 Checkbox en header →  toggleSelectAll()
```

### D2 — Estado Alpine para selección

Reutilizar `selectedFiles: []` ya declarado. Agregar computed helpers:

```javascript
isSelected(file)    → selectedFiles.some(f => f.id === file.id)
toggleSelect(file)  → añadir/quitar de selectedFiles
selectAll()         → selectedFiles = [...files]
clearSelection()    → selectedFiles = []
hasSelection        → selectedFiles.length > 0
```

### D3 — Barra de acciones flotante

```
┌──────────────────────────────────────────────────────┐
│  ☑ 3 elementos seleccionados    [✕ Limpiar] [⬇ ZIP]  │
└──────────────────────────────────────────────────────┘
```

- Aparece con `x-show="hasSelection"` encima de la grilla/lista.
- Se oculta cuando `selectedFiles` queda vacío.
- El botón ZIP está deshabilitado si algún elemento no tiene permiso de lectura (raro, pero posible).

### D4 — Endpoint `POST /files/download-multi`

**Por qué POST y no GET:** El array de IDs puede ser grande; GET con query params tiene límite de URL.

**Flujo backend:**
```
POST /files/download-multi
Body: { ids: [1, 2, 5, 10] }

1. Validar ids[]  (array, integer, min:1)
2. Por cada File: checkFilePermission(file, 'read')
3. Crear tempnam() ZIP
4. Por cada elemento:
   - Si is_folder: addFolderToZipByPath() (filesystem directo, igual que downloadFolder)
   - Si archivo: addFile() al ZIP en raíz del ZIP
5. zip->close()
6. response()->download(tmp, 'descarga.zip')->deleteFileAfterSend(true)
```

**Naming de entradas en el ZIP:**
- Archivos sueltos → raíz del ZIP: `archivo.mp4`
- Carpetas → subcarpeta en ZIP: `MiCarpeta/sub/archivo.mp4`
- Conflicto de nombres (dos archivos con el mismo nombre): añadir sufijo `_2`, `_3`, etc.

### D5 — Integración con fix-archivos-zip-y-upload-carpeta

El método `addFolderToZip()` del change anterior usa `RecursiveDirectoryIterator`. Para no duplicar código, extraer esa lógica a un método privado reutilizable `addPathToZip(\ZipArchive $zip, string $realPath, string $realBasePath, string $zipPrefix)` que sirva a ambos endpoints.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Seleccionar carpetas grandes → ZIP de GBs | Calcular tamaño total antes de generar el ZIP; rechazar si supera 2 GB (misma lógica que `downloadFolder`) |
| Conflicto de nombres en ZIP raíz | Detectar colisión al añadir y renombrar con sufijo numérico |
| Ctrl+Click puede interferir con selección de texto del OS | Usar `@click.ctrl.prevent` en Alpine para capturar solo el modificador Ctrl |
| Checkbox visible en hover puede no funcionar en táctil (mobile) | En lista, el checkbox siempre visible resuelve mobile; en grid, tap largo podría activar selección (mejora futura) |

## Migration Plan

Sin migraciones. Despliegue:
1. Actualizar `FileController.php` (nuevo método `downloadMulti`, refactorizar lógica ZIP a método privado).
2. Actualizar `routes/web.php` (nueva ruta POST).
3. Actualizar `resources/views/files/index.blade.php` (checkboxes, barra, funciones Alpine).
4. `php artisan view:clear`.

Rollback: revertir los tres archivos.
