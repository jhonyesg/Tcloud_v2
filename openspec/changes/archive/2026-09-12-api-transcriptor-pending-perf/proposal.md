## Why

Después de aplicar el change `2026-09-12-api-transcriptor-index-perf-cache` (archivado), el módulo API Transcriptor pasó de **800 ms – 1.5 s** a **~470 ms warm / ~790 ms cold** en `indexData()`. La percepción de "extremadamente lento al cargar" sigue presente porque el navegador ejecuta **múltiples AJAX en paralelo durante el Alpine init** y varios de ellos todavía tardan >300 ms.

Mediciones del navegador (Playwright, sesión jsuarez, 2026-09-12):

```
Top requests >50ms durante la carga de /ia/api-transcriptor (warm):
  838 ms   GET /ia/api-transcriptor?scope=pending&page=1&per_page=50  (indexData AJAX)
  774 ms   GET /ia/api-transcriptor                                  (HTML render)
  477 ms   GET /ia/api-transcriptor/stats       (HTTP upstream + GROUP BY 387k rows)
  112 ms   GET /ia/api-transcriptor/health      (HTTP upstream)
   98 ms   GET /ia/api-transcriptor/empty-folders (cached 5 min; cold 3.3s)

Total cold-path de los AJAX disparados por Alpine init: ~2.4 s
Total warm-path: ~1.5 s
```

El change archivado ya atacó el cuello dentro de `indexData()` (scope resolution + rootId lookup). Quedan tres cuellos externos:

1. **`/stats`**: hace un `Http::get()` al transcriptor upstream + un `GROUP BY state` sobre `transcriptions` (387k filas) en cada request desde el cliente. En la práctica el operador solo mira estos contadores una vez al cargar la pestaña — debería cachearse en backend.
2. **`/health`**: hace un `Http::get()` al upstream `/health` cada vez que el frontend lo pide. Lo mismo: cacheable en backend.
3. **`/empty-folders`**: ya cachea 5 min, pero el scan toca `scandir()` por cada storage (~70) — la primera carga paga 3.3 s. Aceptable para cold; mejorable con caché progresiva (`Cache::add` race-safe) y/o por-storage en vez de key global.

Adicionalmente, las llamadas AJAX a `/stats`, `/health` y `/empty-folders` se disparan **al mismo tiempo** durante el init de Alpine, serializadas por el event loop del browser cuando comparten la misma conexión HTTP/1.1. Eso amplifica la latencia total.

## What Changes

### Cache del lado backend para `/stats` y `/health`

- `GET /ia/api-transcriptor/stats` → cache Redis **60 s**. Combinar en una sola key `transcriptor:stats:combined` que incluya:
  - `local` (GROUP BY actual, ya barato en warm)
  - `upstream` (`getStats()`)
  - `cached_at`
- Invalidación:
  - `CacheEpoch::bump()` en cualquier mutación que afecte contadores (transcripción creada / terminada / fallida).
  - `WatermarkReconciler` ya invalida caches globales; sumamos `Cache::forget('transcriptor:stats:combined')` ahí.
- `GET /ia/api-transcriptor/health` → cache Redis **30 s** con `Cache::remember`. Key: `transcriptor:health:combined`.

### Cache por-storage de `/empty-folders`

- Reemplazar la key global `transcriptor:empty_folders:max{maxDirs}` por una key **por storage** con TTL **10 min**: `transcriptor:empty_folders:{storage_id}:max{maxDirs}`.
- El scan global se hace una sola vez al primer request (3.3 s), los subsiguientes son N lookups en Redis (<5 ms total).
- Invalidar cuando se mute `base_path` o `transcription_enabled` (mismo punto que el scope cache).
- Beneficio colateral: si el operador abre la pestaña varias veces en una hora, no paga el scan de filesystem.

### Reducir el HTML render del index

- El template `ia/api-transcriptor/index.blade.php` tiene **4850 líneas**. Blade compila cada request (aunque cachea el PHP compilado en `storage/framework/views/`). El render de la vista + serialización del JSON para Alpine pesa ~700-800 ms en cold (medido con `GET /ia/api-transcriptor` HTML).
- Acción concreta: extraer el panel `_pipeline-diagnostics` (que NO está relacionado con la lista de jobs ni con los storages) a una sub-pestaña o a un endpoint AJAX lazy-loaded. Hoy se renderiza inline en cada carga.
- Esta parte requiere análisis del view + identificación de qué paneles se pueden mover. Es trabajo de UX, no puramente de perf.

### Header `Cache-Control` en endpoints cacheados

- Para que el navegador NO revalide en cada navegación entre pestañas: agregar `Cache-Control: max-age=30, private` en `/stats` y `/health`. Hoy no tienen header (vienen por defecto `no-store` en Laravel si no se especifica).

## Capabilities

### New Capabilities
- `transcriptor-stats-health-cache`: los endpoints `/stats` y `/health` SHALL responder en menos de **50 ms** en cache caliente (warm) y SHALL invalidarse automáticamente al mutar `transcriptions` o `transcription_enabled` en cualquier storage. La cache SHALL vivir en Redis con TTL configurable vía `SystemSetting('transcriptor_stats_cache_ttl')` (default 60 s) y `transcriptor_health_cache_ttl` (default 30 s).
- `transcriptor-empty-folders-per-storage-cache`: el endpoint `/empty-folders` SHALL cachear por storage en lugar de globalmente, SHALL responder en menos de **20 ms** en cache caliente y SHALL invalidarse en cada mutación de `base_path` o `transcription_enabled`.

## Impact

- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`:
  - `stats()`: envolver en `Cache::remember(transcriptor:stats:combined, 60, fn() => ...)`.
  - `health()`: envolver en `Cache::remember(transcriptor:health:combined, 30, fn() => ...)`.
  - `emptyFolders()`: refactor del `Cache::remember` global a lookup por storage + ensamblador final.
- `app/app/Services/Ia/WatermarkReconciler.php`: agregar `Cache::forget('transcriptor:stats:combined')` en los puntos donde hoy se hace `CacheEpoch::bump()`.
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::toggleStorage()`: agregar forgets de las nuevas keys.
- `app/resources/views/ia/api-transcriptor/index.blade.php`: extraer `_pipeline-diagnostics` a lazy-load (sub-pestaña o AJAX on-demand). Reduce el HTML payload de ~150 KB a <80 KB.
- Sin migraciones. Sin breaking changes de API.
- Sin cambio de UI para el operador (los contadores y badges siguen iguales, solo se sirven más rápido).

## Non-goals

- **No** se cambia el comportamiento del contador upstream (`getStats()` HTTP call). Solo se cachea su respuesta.
- **No** se modifica la query `GROUP BY state` de `transcriptions` (ya usa índice `state_created_at_index`, es eficiente en warm).
- **No** se rediseña `_pipeline-diagnostics`. Solo se mueve de lugar en el template.
- **No** se hace HTTP/2 server push ni otras optimizaciones de transporte. Es nginx/PHP-FPM standard; la mejora viene del backend cache.
- **No** se agrega prefetch desde el cliente (e.g. `<link rel="prefetch">`). Eso sería trabajo del frontend.

## Medición esperada

```
Antes (warm, post change archivado):
  /stats         477 ms   ───┐
  /health        112 ms     ├──>  Total ~600 ms solo en estos AJAX
  /empty-folders 122 ms   ───┘

Después (warm):
  /stats          <5 ms   (cache 60s)
  /health         <5 ms   (cache 30s)
  /empty-folders  <5 ms   (per-storage cache, lookups en Redis)

Ahorro: ~580 ms en el init de Alpine
Page load warm total esperado: ~3.5 s → ~1.5 s
```

## Riesgos

| Riesgo | Mitigación |
|--------|-----------|
| Stats stale 60 s | El operador rara vez mira el contador por más de un minuto; aceptable. Si quiere tiempo real: `SystemSetting('transcriptor_stats_cache_ttl', '0')` bypass. |
| Health cache stale durante caída del upstream | 30 s de latencia para detectar caída. Aceptable para indicador visual; las llamadas reales (`/jobs/{id}/status`, `/transcribe/{id}`) hacen HTTP directo, no cachean. |
| Empty-folders per-storage cache crece en Redis | Cap de ~70 storages × 1 key = 70 keys, ~10 KB cada una. Trivial. |
| Múltiples invalidaciones por mutación | `Cache::forget` es idempotente; sin race condition documentada. |
| Race en scan inicial cuando 2 requests llegan al mismo tiempo | `Cache::lock()` para serializar el compute (mismo patrón que `StorageSyncService::fullSync`). |

## Follow-up (no incluido)

- Rediseño del panel `_pipeline-diagnostics` (UX, no perf) — worktree aparte.
- HTTP/2 multiplexing si el reverse proxy no lo tiene ya (verificar config nginx).
- Cachear también `GET /jobs/{id}/status` (polling cada 2 s del modal) — pero ahí hay cambio de trade-off (frescura vs carga).
