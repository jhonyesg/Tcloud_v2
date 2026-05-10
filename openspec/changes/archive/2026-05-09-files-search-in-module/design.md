## Context

El módulo de archivos usa `FileController::index()` para servir los archivos de una carpeta. El método tiene dos caminos: para storages `local` delega al `StorageSyncService::syncFolder()` que sincroniza el filesystem físico con la DB antes de responder; para S3 consulta directamente la DB. Ninguno de los dos caminos soporta búsqueda por nombre.

En el frontend, Alpine.js llama a `loadFiles()` que construye `GET /files?parent_id=X&storage_id=Y`. No existe debounce ni estado de búsqueda.

El navbar tiene un input `<input placeholder="Buscar archivos...">` sin ningún manejador de eventos — es decorativo y genera confusión.

## Goals / Non-Goals

**Goals:**
- Cuadro de búsqueda funcional dentro del módulo de archivos (barra de herramientas).
- Búsqueda en el storage activo a través de todas las carpetas (no solo la carpeta actual).
- Backend filtra por `name LIKE %q%` con restricciones de permiso del usuario.
- Debounce de 350ms en el frontend para no saturar el API con cada tecla.
- Indicador visual de "modo búsqueda" y botón para volver a la carpeta actual.
- Eliminar el input decorativo del navbar.

**Non-Goals:**
- Búsqueda por contenido de archivos (solo por nombre).
- Búsqueda entre múltiples storages simultáneamente.
- Resaltado de fragmentos coincidentes en los resultados.
- Historial de búsquedas.

## Decisions

### 1. Búsqueda siempre contra la DB, incluso para storages `local`
**Decisión**: Cuando llega el parámetro `q`, el controlador salta el camino de `syncFolder()` y consulta directamente `File::query()` con `LIKE`. La DB está sincronizada porque `syncFolder()` se ejecutó en navegaciones previas.

**Por qué**: `syncFolder()` solo sincroniza una carpeta a la vez; hacer sync de todo el árbol para una búsqueda es costoso. La DB es la fuente de verdad para búsquedas cross-folder. La tolerancia a datos desactualizados (archivos añadidos externamente al filesystem sin haber navegado la carpeta) es aceptable para esta feature.

**Alternativas consideradas**:
- *Sync completo antes de buscar*: demasiado lento para storages grandes.
- *Endpoint separado `/files/search`*: innecesario, el parámetro `q` en el mismo endpoint es más limpio.

### 2. Debounce de 350ms en Alpine.js con `x-model` + watcher
**Decisión**: Usar `$watch('searchQuery', ...)` con `clearTimeout`/`setTimeout` para disparar `searchFiles()` solo cuando el usuario deja de escribir.

**Por qué**: El endpoint de búsqueda hace un `LIKE` en la DB. Sin debounce, se lanzarían N requests por cada carácter. 350ms es el estándar de UX para búsqueda reactiva.

### 3. Búsqueda muestra resultados planos (sin jerarquía de carpetas)
**Decisión**: En modo búsqueda, el `x-for` de la tabla muestra los resultados tal cual llegan del API. No se navega al padre de cada resultado.

**Por qué**: Mostrar la ruta completa de cada archivo (breadcrumb) requeriría datos extra del backend. Para una primera iteración, la lista plana con el nombre es suficiente y más rápida de implementar. El usuario puede hacer clic en una carpeta de los resultados para navegar a ella normalmente.

### 4. Scoped al `currentStorage`, no al `currentFolder`
**Decisión**: La búsqueda ignora `parent_id` y usa solo `storage_id` del storage activo.

**Por qué**: Si se buscara solo en la carpeta actual, los resultados serían tan limitados como el directorio listado. La búsqueda debe ser global dentro del storage para ser útil.

## Risks / Trade-offs

- **Datos desactualizados en local storage** → Si se añaden archivos directamente al filesystem sin haber navegado esa carpeta, no aparecerán en búsqueda. Mitigación: documentar que la búsqueda usa el índice de la DB.
- **Queries lentas en storages con miles de archivos** → `LIKE '%q%'` no usa índices en PostgreSQL. Mitigación: se puede añadir un índice parcial en `name` si se requiere escala; para el volumen actual es aceptable. El parámetro `q` requiere mínimo 2 caracteres para evitar resultados masivos.
- **Estado de UI al limpiar búsqueda** → Al limpiar, se debe volver a la carpeta y estado previos correctamente. Mitigación: se conserva `currentFolder` durante la búsqueda y se restaura al limpiar.
