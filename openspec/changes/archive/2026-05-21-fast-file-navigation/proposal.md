## Why

El sistema tiene 1,004,833 archivos en BD y 23,064 carpetas. El cron `storage:sync --all` (cada 15 min) escanea fisicamente todas las carpetas via `scandir()`, aunque el 99% son dias pasados completamente inmutables -- esto escala linealmente y ya toma ~4 minutos de I/O cada ciclo. La navegacion en el frontend devuelve hasta 823 archivos en una sola respuesta (295 KB JSON, query fria de 240 ms) sin paginacion ni cache, lo que hace la carga visible incluso con indices correctos.

## What Changes

- **Smart sync con mtime** (`StorageSyncService`): antes de invocar `scandir()` sobre una carpeta, comparar el `mtime` del directorio en filesystem con `file_modified_at` de la carpeta en BD. Si no cambio, skip. Reduce de 23,064 `scandir()` a ~360 por run de cron (solo carpetas del dia activo).
- **Redis cache para listados** (`FileController`): cachear el resultado de cada query `WHERE parent_id=X` en Redis. TTL de 5 min para carpetas del dia actual, 24 h para dias pasados. Invalidar la entrada de cache cuando `syncFolder()` detecta y persiste cambios reales en esa carpeta.
- **Paginacion de archivos** (API + frontend): la ruta `GET /files` acepta `?page=N&per_page=100`. El frontend usa scroll infinito para cargar lotes sin reemplazar los anteriores. Elimina payloads de 295 KB y el render de 800+ DOM nodes de golpe.

## Capabilities

### New Capabilities
- `smart-sync-mtime`: Logica de skip en `fullSync()` usando mtime de directorio para evitar re-escanear carpetas inmutables
- `folder-listing-cache`: Cache Redis de resultados de listado con TTL diferenciado e invalidacion por sync
- `file-listing-pagination`: Paginacion server-side del endpoint `/files` con scroll infinito en frontend

### Modified Capabilities
- `spa-navigation`: El componente `fileManager` del frontend gestiona estado de paginacion (pagina actual, hay mas?) y la carga incremental de lotes adicionales al hacer scroll

## Impact

- `app/app/Services/StorageSyncService.php` -- skip por mtime en `fullSync()`, invalidacion de cache tras escribir cambios
- `app/app/Http/Controllers/FileController.php` -- capa de cache Redis + paginacion en `index()`
- `app/resources/views/files/index.blade.php` -- scroll infinito, estado `hasMore` y `currentPage`
- No requiere nueva migracion de base de datos

## Non-goals

- No se cambia la estructura de carpetas ni el modelo `files`
- No se aplica paginacion a resultados de busqueda (scope separado)
- No se expone invalidacion manual de cache al usuario (el sync automatico la maneja)
- No se modifica la frecuencia del cron (15 min sigue siendo adecuado para el dia actual)
