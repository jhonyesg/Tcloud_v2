## 1. Preparacion — Escribir file_modified_at de carpetas en syncFolder()

- [x] 1.1 En `StorageSyncService::syncFolder()`, actualizar `file_modified_at` con `filemtime($realPath)` siempre que `$parentId !== null`. Si hubo cambios, invalidar cache.
- [x] 1.2 `syncRootFolder()` no necesita actualizar nada (raiz siempre se procesa)
- [x] 1.3 `php artisan storage:sync --all` ejecutado — 16,786/16,786 carpetas con `file_modified_at` poblado (100%)

## 2. Smart sync con mtime en fullSync()

- [x] 2.1 En `fullSync()`, obtener `$realPath` para cada carpeta y verificar que existe
- [x] 2.2 Si `file_modified_at != null` y `filemtime <= file_modified_at->timestamp` -> `continue` (skip)
- [x] 2.3 Path no existente -> syncFolder() lo maneja (detecta huerfanos)
- [x] 2.4 Verificado: segundo run del cron toma ~14s (vs ~4 min antes). Por storage: ~0.1s vs ~1.1s (10x mas rapido)

## 3. Cache Redis para listados en FileController

- [x] 3.1 Cache key con generacion: `folder_listing:{storageId}:{pid}:{gen}:{page}` donde gen es un contador que se incrementa en invalidacion
- [x] 3.2 `Cache::get($cacheKey)` — HIT retorna directamente (1.65ms vs 214ms)
- [x] 3.3 Cache MISS: TTL root=60s, dia actual=300s, dia pasado=86400s
- [x] 3.4 `Cache::put($cacheKey, $responseData, $ttl)` con pagination incluida
- [x] 3.5 `sync=1` invalida cache via `Cache::increment("folder_gen:...")` — O(1), sin scan

## 4. Paginacion server-side en FileController

- [x] 4.1 `$page = max(1, ...)` y `$perPage = min(200, max(10, ...))`
- [x] 4.2 `->paginate($perPage, ['*'], 'page', $page)`
- [x] 4.3 Respuesta incluye `pagination.page`, `pagination.per_page`, `pagination.total`, `pagination.has_more`
- [x] 4.4 Cache key incluye `$page`

## 5. Scroll infinito en frontend (Alpine.js)

- [x] 5.1 Estado: `currentPage`, `hasMore`, `isLoadingMore`, `_fetchMoreController`
- [x] 5.2 `loadFiles()` resetea a pagina 1 y lee `data.pagination`
- [x] 5.3 `loadMore()` hace append a `this.files`
- [x] 5.4 Reset paginacion en `navigateToFolder()`, `navigateToBreadcrumb()`, `goToStorageRoot()`, `enterStorage()`
- [x] 5.5 IntersectionObserver sobre `x-ref="loadMoreSentinel"` con `rootMargin: '200px'`
- [x] 5.6 Indicador "Cargando mas archivos..." visible cuando `isLoadingMore === true`

## 6. Actualizacion de spec obsoleto

- [x] 6.1 Eliminado `specs/file-navigation-fast/` del change

## 7. Verificacion

- [x] 7.1 Cron verificado: segunda corrida completa en ~14s (antes ~4 min). Storages: 0.1s c/u (antes 1.1s)
- [x] 7.2 Cache Redis verificado: 1.65ms GET (vs 214ms query BD fria). TTL 24h para dias pasados.
- [x] 7.3 Paginacion verificada: payload pagina 1 = 35.8 KB (vs 295 KB sin paginar)
- [x] 7.4 Probar en UI: scroll infinito carga lotes adicionales y seleccion se mantiene entre paginas
- [x] 7.5 Probar en UI: boton "Actualizar" invalida cache (siguiente navegacion va a BD)
