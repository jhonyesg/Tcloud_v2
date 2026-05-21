## Context

El sistema ya tiene `rename()` (disk: ninguno, BD: `name`) y `destroy()` (disk: `unlink`/`rmdir` recursivo, BD: delete). El patrón para operaciones de disco está establecido en `FileController`: validar permiso → resolver `realpath` → operar en disco → sincronizar BD. `StorageSyncService` resuelve la ruta física como `base_path + '/' + file->path`. Los permisos usan la jerarquía `read < write/upload < full`; copiar y mover son operaciones destructivas desde la perspectiva del destino, por eso se restringen a `full`.

## Goals / Non-Goals

**Goals:**
- Endpoints `POST /files/{id}/copy` y `POST /files/{id}/move` seguros, con validación de path traversal.
- Operación recursiva correcta para carpetas (disco + BD).
- UX mínima viable: modal de selección de destino que muestra carpetas del storage actual.
- Manejo de conflictos de nombre en destino (error 409).

**Non-Goals:**
- Copia/movimiento entre storages distintos.
- Drag-and-drop visual.
- Portapapeles persistente cross-navegación.
- Storages S3.

## Decisions

### 1. Un endpoint por operación, no uno genérico

**Decisión:** `POST /files/{id}/copy` y `POST /files/{id}/move` separados.

**Alternativa descartada:** Un único endpoint `POST /files/{id}/transfer` con `action: copy|move` — más difícil de autorizar y documentar con semántica clara.

### 2. Move de carpeta: `rename()` a nivel de directorio + UPDATE recursivo en BD

**Decisión:** Para mover una carpeta, usar PHP `rename($srcPath, $dstPath)` que mueve el árbol completo del sistema de archivos en una operación atómica (en el mismo filesystem). Luego actualizar todos los registros de BD donde el `path` empieza con el prefijo antiguo usando un `UPDATE ... WHERE path LIKE 'old/%'`.

**Alternativa descartada:** Mover entrada por entrada recursivamente — más lento y propenso a fallos parciales.

**Riesgo cross-filesystem:** Si `base_path` y destino están en distintos filesystems, `rename()` falla. En este proyecto todos los storages locales son del mismo servidor, por lo que no aplica.

### 3. Copy de carpeta: copia recursiva con `RecursiveDirectoryIterator` + insert batch en BD

**Decisión:** Recorrer el árbol con `RecursiveIteratorIterator`, copiar cada archivo con `copy()`, crear registros BD para cada nuevo archivo/carpeta. El método privado `copyRecursively(File $src, int $dstParentId)` maneja la recursión.

### 4. Selección de destino en frontend: modal con árbol de carpetas del storage actual

**Decisión:** Al hacer click en "Mover" o "Copiar", se abre un modal Alpine que hace fetch de las carpetas del storage actual (lazy, nivel por nivel) y permite navegar y seleccionar una carpeta destino o la raíz.

**Estado Alpine nuevo:**
- `showCopyMoveModal: false`
- `copyMoveAction: null` (`'copy'` | `'move'`)
- `copyMoveSourceFile: null`
- `copyMoveDestFolderId: null` (null = raíz)
- `copyMoveFolderTree: []` (carpetas cargadas para el modal)

### 5. Manejo de conflictos: error 409, sin auto-rename

**Decisión:** Si en destino ya existe un archivo/carpeta con el mismo nombre, retornar 409 con mensaje claro. No se implementa auto-renombrado (e.g., `archivo (2).mp4`) para mantener la lógica simple y consistente con el comportamiento de `upload()`.

### 6. Orden invariante: disco primero, BD después

**Decisión:** En todas las operaciones (copy archivo, copy carpeta recursivo, move archivo, move carpeta) la operación en disco ocurre **antes** de cualquier escritura en BD. La ruta física siempre se resuelve como `$storage->base_path . '/' . $file->path`.

- Si falla el disco → no se toca BD → estado consistente.
- Si falla BD después del disco → el archivo existe en disco pero no en BD → rescan puede recuperarlo.
- El camino inverso (BD primero, disco después) dejaría registros huérfanos apuntando a rutas inexistentes.

## Risks / Trade-offs

- **[Riesgo] Copy de carpeta grande** → La operación puede tardar si hay miles de archivos. No hay feedback de progreso en esta versión — el botón queda deshabilitado (loading state) hasta completar.
- **[Riesgo] BD y disco inconsistentes si falla a mitad de copia** → No hay transacción que cubra también el disco. Mitigación: la copia en disco se hace primero; si falla BD, el usuario puede usar rescan para sincronizar.
- **[Trade-off] Move de carpeta usa LIKE en BD** → `WHERE path LIKE 'old_path/%'` requiere que los paths sean consistentes. Ya es un invariante del sistema (todos los paths se construyen jerárquicamente en `generatePath()`).
