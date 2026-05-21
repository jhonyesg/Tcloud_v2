## Context

El módulo de archivos (`FileController`, `resources/views/files/index.blade.php`) tiene dos bugs relacionados con operaciones de carpetas:

1. **ZIP vacío**: `downloadFolder()` usa `addFolderToZip()` que consulta `File::where('parent_id', ...)` recursivamente. Como `syncFolder()` solo sincroniza un nivel del filesystem a la DB, las sub-carpetas no navegadas están vacías en DB → ZIP sin contenido. Al reintentar después de navegar funciona porque la DB ya fue poblada.

2. **Upload de carpeta**: El modal de upload solo tiene `<input type="file" multiple>` — sin `webkitdirectory`. El handler `uploadFiles()` trata cada archivo como plano, sin preservar `webkitRelativePath`. El drop handler usa `dataTransfer.files` que ignora carpetas arrastradas.

## Goals / Non-Goals

**Goals:**
- ZIP siempre correcto, independientemente del estado de sincronización DB.
- Subida de carpetas (selector + drag & drop) con estructura jerárquica preservada.
- Sin regresiones en upload de archivos individuales.

**Non-Goals:**
- Soporte para storages S3 (solo filesystem local).
- Subida de carpetas en shares públicos.
- Progreso granular por sub-carpeta.
- Cancelación de uploads en progreso.

## Decisions

### D1 — ZIP: filesystem directo en lugar de sync previo

**Opción A (elegida):** Reemplazar `addFolderToZip()` por `RecursiveDirectoryIterator` sobre el filesystem real. La ruta base ya está validada con `realpath()` y el path de la carpeta está en `$folder->path`.

**Opción B:** Ejecutar `syncFolder()` recursivamente antes de generar el ZIP. Más lenta (N consultas al disco + escrituras DB), y si el directorio tiene miles de archivos la sincronización puede exceder el timeout de PHP.

**Rationale:** La opción A es más simple, más rápida y no tiene estado intermedio que pueda fallar. El único dato que necesitamos del disco (la ruta física) ya lo tenemos.

```
FLUJO NUEVO (ZIP)
═══════════════════════════════════════
downloadFolder()
  → realpath($storage->base_path . '/' . $folder->path)  ← validado
  → new RecursiveDirectoryIterator($folderRealPath)
  → RecursiveIteratorIterator
  → por cada archivo: zip->addFile($realPath, $zipEntry)
  → response()->download(...)
```

### D2 — Upload de carpetas: webkitdirectory + construcción de árbol cliente

**Flujo elegido:**

```
FLUJO NUEVO (upload carpeta)
═══════════════════════════════════════
Usuario selecciona carpeta
  → <input webkitdirectory>
  → files[i].webkitRelativePath = "MiCarpeta/sub/archivo.txt"

uploadFolder(fileList)
  1. Extraer paths únicos de carpetas
     ["MiCarpeta", "MiCarpeta/sub"]
  2. Ordenar por profundidad (padres primero)
  3. POST /files {name, parent_id, storage_id, is_folder:true}
     → guardar { "MiCarpeta" → id:42, "MiCarpeta/sub" → id:43 }
  4. Para cada archivo:
     parentPath = webkitRelativePath sin nombre de archivo
     parentId   = pathToId[parentPath]
     → uploadFile(file, parentId)
```

**Estado Alpine.js adicional:**
- `uploadFolderMode: false` — distingue modo carpeta vs archivo en el modal.
- `uploadFolderProgress: { total, done, current }` — para mostrar progreso.

### D3 — Drag & drop de carpetas: FileSystemEntry API

`dataTransfer.files` solo devuelve archivos, ignora carpetas. La FileSystemEntry API (`item.webkitGetAsEntry()`) devuelve un `FileSystemDirectoryEntry` que se puede leer recursivamente con `readEntries()`.

```javascript
// Handler de drop nuevo
async function handleDrop(event) {
    const items = [...event.dataTransfer.items];
    const hasFolder = items.some(i => i.webkitGetAsEntry()?.isDirectory);
    if (hasFolder) {
        const allFiles = await readEntriesRecursive(items);
        uploadFolder(allFiles);  // FileList-like con webkitRelativePath simulado
    } else {
        uploadFiles(event.dataTransfer.files);  // flujo existente
    }
}
```

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| `webkitdirectory` es no-estándar (Chrome/Firefox/Edge lo soportan, Safari desde 2021) | Documentar limitación; el botón de archivos individuales sigue disponible como fallback |
| Carpeta grande con miles de archivos: las llamadas POST de creación de carpetas son seriales | Aceptable para casos de uso típicos; si se necesita mejorar, se puede paralelizar en una iteración futura |
| `readEntries()` de la FileSystemEntry API devuelve máximo 100 entries por llamada → necesita loop | Implementar loop `while` hasta que `readEntries` devuelva array vacío |
| ZIP con `RecursiveDirectoryIterator`: symlinks pueden apuntar fuera del basePath | Usar `RecursiveDirectoryIterator::FOLLOW_SYMLINKS` desactivado (default); validar cada `realpath()` contra `$realBasePath` |
| Conflicto de nombres al crear carpetas: si ya existe carpeta con ese nombre, `POST /files` retorna 409 | En `uploadFolder()`, capturar 409 y continuar usando el ID existente (hacer GET de la carpeta existente) |

## Migration Plan

Sin migraciones de DB. El despliegue es:
1. Actualizar `FileController.php` (fix ZIP).
2. Actualizar `resources/views/files/index.blade.php` (upload carpeta + drop).
3. Limpiar caché de vistas: `php artisan view:clear`.

Rollback: revertir ambos archivos — no hay cambios de estado persistente.
