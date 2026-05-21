## Context

TCloud tiene 1M+ archivos en BD y 23,064 carpetas organizadas por storage/dia/camara. El cron `storage:sync --all` corre cada 15 min via Laravel Scheduler e invoca `StorageSyncService::fullSync()`, que itera TODAS las carpetas de BD y llama `scandir()` sobre cada una -- incluyendo miles de dias pasados que son inmutables. Redis esta disponible como cache driver (ya configurado). La tabla `files` tiene el indice compuesto `idx_files_listing (storage_provider_id, parent_id, is_folder DESC, created_at DESC)` que cubre la query de listado, pero devolver 800+ filas sin paginar genera payloads de 295 KB.

## Goals / Non-Goals

**Goals:**
- Reducir el trabajo del cron de 23,064 scandir() a ~360 por run (solo carpetas activas del dia)
- Eliminar queries frias de 240ms usando Redis como capa de cache para listados
- Limitar payloads de respuesta a ~35 KB por lote (100 archivos) con scroll infinito

**Non-Goals:**
- Cambiar la frecuencia del cron (15 min es apropiado para el dia actual)
- Paginacion en resultados de busqueda
- Invalidacion manual de cache por el usuario
- Cambiar el modelo de datos de `files`

## Decisions

### D1: Comparar mtime de directorio antes de scandir()

**Decision**: En `StorageSyncService::fullSync()`, antes de llamar `syncFolder($folder->id)` para cada carpeta existente, obtener `filemtime($realPath)` y compararlo con `$folder->file_modified_at`. Si el mtime del filesystem es igual o anterior al timestamp guardado en BD, hacer skip de esa carpeta.

```
fullSync() ACTUAL:
  foreach 23,064 folders → scandir() siempre

fullSync() NUEVO:
  foreach 23,064 folders:
    mtime = filemtime(path)
    if mtime <= folder.file_modified_at → skip (nada cambio)
    else → syncFolder() + actualizar file_modified_at de la carpeta
```

La carpeta raiz (sin parent) siempre se procesa para detectar nuevas subcarpetas.

**Por que esta**: `filemtime()` es O(1) y no requiere abrir el directorio. Para dias pasados (mtime = hace semanas), el skip es inmediato. La semantica de `file_modified_at` ya existe en el modelo -- solo hay que escribirla correctamente para carpetas tras cada sync.

**Alternativa descartada**: Filtrar por fecha en el nombre de carpeta (fragil, depende de convencion de nombres).

---

### D2: Cache Redis con TTL diferenciado por "edad" de carpeta

**Decision**: En `FileController::index()`, antes de consultar la BD, intentar leer desde Redis:

```
cache_key = "folder_listing:{storage_id}:{parent_id}:{page}"

TTL logica:
  - Si parent_id pertenece a carpeta con file_modified_at de hoy → TTL 5 min
  - Si parent_id pertenece a carpeta de dia pasado → TTL 86400s (24h)
  - Si parent_id es null (raiz de storage) → TTL 60s

Invalidacion:
  - Cuando syncFolder() hace INSERT/UPDATE/DELETE de hijos → Cache::forget(key)
  - Cuando el usuario hace sync=1 sobre una carpeta → Cache::forget(key)
```

La deteccion de "carpeta de hoy" se hace consultando `file_modified_at` del registro padre en BD (una query de 1 fila, < 1ms con indice).

**Por que esta**: Redis ya esta en el stack. Una carpeta de dia pasado con 800 archivos no cambia nunca -- cachearla 24h es correcto y elimina completamente la query fria de 240ms para todas las visitas subsequentes.

**Alternativa descartada**: HTTP Cache-Control headers -- no funciona bien con Alpine.js fetch() y no permite invalidacion granular.

---

### D3: Paginacion server-side con cursor de pagina

**Decision**: `FileController::index()` acepta `?page=N&per_page=100` (default: page=1, per_page=100). Responde con:

```json
{
  "files": [...],         // lote de hasta 100
  "breadcrumbs": [...],
  "pagination": {
    "page": 1,
    "per_page": 100,
    "total": 823,
    "has_more": true
  }
}
```

La query usa `LIMIT 100 OFFSET (page-1)*100`. El cache key incluye el numero de pagina.

**Frontend**: Estado Alpine agrega `currentPage`, `hasMore`, `isLoadingMore`. Al hacer scroll hasta el 80% del contenedor de archivos, se dispara `loadMore()` que hace GET con `page=currentPage+1` y hace APPEND a `this.files` (no reemplaza).

**Por que esta**: Paginacion por offset es suficiente para este caso -- los usuarios raramente pasan de pagina 3 en un navegador de archivos. Cursor-based pagination agrega complejidad sin beneficio real aqui.

---

### D4: Escribir file_modified_at de carpeta en cada syncFolder()

**Decision**: Al final de `syncFolder()`, si hubo cambios (created > 0 o deleted > 0), actualizar `file_modified_at` del registro de la carpeta en BD con `Carbon::now()`. Si no hubo cambios, NO actualizar (para que D1 pueda hacer skip correctamente la proxima vez).

**Por que esta**: Es la fuente de verdad para D1. Sin este update, todas las carpetas tendrian `file_modified_at = null` y nunca podrian ser skipeadas.

## Risks / Trade-offs

- **[Riesgo] Carpeta con mtime incorrecto**: Algunos sistemas de archivos en red (NFS, CIFS) no actualizan mtime de directorio confiablemente. → **Mitigacion**: Documentar que el comportamiento depende del filesystem. Para estos casos, el usuario puede presionar "Actualizar" (sync=1) para forzar.
- **[Trade-off] Cache stale entre sync runs**: Con TTL de 5 min para carpetas activas, un archivo creado externamente puede no aparecer hasta el proximo ciclo. → **Aceptable**: El comportamiento es identico al de Google Drive / Dropbox.
- **[Riesgo] Scroll infinito con operaciones de seleccion masiva**: Si el usuario selecciona archivos y luego hace scroll (cargando mas), la seleccion previa debe mantenerse. → **Mitigacion**: `loadMore()` hace append a `files[]`, el estado `selectedFiles[]` no se toca.
- **[Trade-off] Paginacion vs. "ver todos"**: Con 800 archivos paginados de 100 en 100, hacer Ctrl+A selecciona solo los 100 cargados, no los 800. → **Aceptado para este scope**: Seleccion masiva es una mejora futura.

## Migration Plan

1. Deploy D4 primero (escribir `file_modified_at` en syncFolder) -- hace que el cron "caliente" los valores para D1
2. Correr `php artisan storage:sync --all` manualmente una vez para poblar todos los `file_modified_at`
3. Deploy D1 (skip por mtime) -- el cron se vuelve eficiente inmediatamente
4. Deploy D2 (cache Redis) -- las queries se sirven desde Redis
5. Deploy D3 (paginacion) -- ultimo porque requiere cambios frontend coordinados
