## Why

Auditamos la navegación de los 19 módulos admin/operador de TCloud con Playwright (sesión jsuarez, 2026-09-13). El objetivo era identificar módulos cuya latencia de carga rompe la fluidez de navegación. Los módulos del usuario se sienten "lentos al cambiar de pestaña" o "lentos al volver después de un rato" — sin saber dónde atacar.

### Ranking completo (warm load, ordenado peor → mejor)

```
 # MODULO                          COLD     WARM   AJAX  TOP SLOW WARM AJAX
 1 Avisos Inteligentes           11122ms  11257ms   16  /scan=4903ms
 2 Correcciones                   3871ms   3669ms   21  /ai-suggest-status=739ms
 3 API Transcriptor               7518ms   3451ms   13  /?scope=pending=869ms
 4 Admin Storages                 2757ms   2174ms   10  /admin/storages=1207ms
 5 Admin Sessions                 1243ms   1577ms   10  /sessions=334ms
 6 Grabaciones - Grabadores       1313ms   1282ms   11
 7 Shares                         1332ms   1281ms   10
 8 Admin Media Editor             1134ms   1264ms   11
 9 Mis Archivos                   1316ms   1203ms   11
10 Grabaciones - Canales          1261ms   1177ms    9
11 Mis Avisos                     1277ms   1167ms   13
12 Correo                         1142ms   1150ms   12
13 Admin External Sites           1154ms   1096ms   10
14 Admin Redis                    1531ms   1060ms   10
15 Dashboard                      998ms   1050ms    9
16 Papelera                       1021ms   1044ms    9
17 Admin Usuarios                  985ms    972ms   10
18 Admin Postgres                  975ms    966ms    9
19 Profile                         948ms    911ms    8
```

### Top cuellos

**1. Avisos Inteligentes** (`/ia/avisos-inteligentes`) — 11.3s warm, AJAX estrella: `/scan` 4.9s
- `AvisosInteligentesController::scanStatus()` ejecuta `service->estimate()` DOS veces + `service->recentRuns(10)` + `service->lastRun()` + `service->settings()`. Cada `estimate()` recorre la tabla `transcriptions` (~387k filas) + joins con `files` + joins con `keywords` para contar candidatos. Sin cache.
- Cambio: cachear el aggregate 60s + invalidar al mutar transcripciones o keywords. También devolver `last_run` y `recent_runs` cacheados.

**2. Correcciones** (`/ia/correcciones`) — 3.6s warm, AJAX estrella: `/ai-suggest-status` 739ms
- `CorreccionesController::aiSuggestStatus()` ejecuta un query de estado de las corridas de IA. Cacheable 30s.
- También cachear `ai-suggest-settings` (147ms) y `mining-status` (138ms).

**3. API Transcriptor** (`/ia/api-transcriptor`) — 3.4s warm
- El AJAX `indexData` (`?scope=pending`) sigue en 869ms warm. Aunque mi change archivado (`2026-09-12-api-transcriptor-index-perf-cache`) ya cacheó scope/rootId/cantidad/falta reducir la iteración PHP sobre 190 storages.
- El HTML render 674ms — fuera del scope de cache backend, requiere reducción de template o lazy load.

**4. Admin Storages** (`/admin/storages`) — 2.1s warm, AJAX estrella: `/admin/storages` 1207ms
- `StorageProviderController::index()` no cachea la lista de storages + stats agregadas (cantidad de archivos, transcripciones pendientes, etc.). Recalcula todo en cada request.

### Lo que NO se ataca en este change (acceptable)

- **Módulos 5-19**: latencia warm 0.9-1.6s es razonable para un dashboard admin con AJAX. Optimización marginal no justifica el riesgo.
- **HTML render del template del API Transcriptor (674ms warm)**: cambiar el template o fragmentarlo requiere UX/UI work.
- **HTML render de Avisos Inteligentes**: idem, requiere restructuración.

## What Changes

### 1. Cache de `/ia/avisos-inteligentes/scan` (Redis 60s)

- Cachear la respuesta de `scanStatus()` con TTL 60s. Key: `avisos:scan_status`.
- Invalidar en:
  - Mutación de cualquier `transcription` (created, completed, failed).
  - Mutación de `keywords` (creadas/eliminadas).
  - `WatermarkReconciler` (cualquier `bump` también limpia esta cache).
  - Cambio de settings de scan (`saveScanSettings`).
- Resultado esperado: `/scan` cold 5s → warm <50ms.

### 2. Cache de `/ia/correcciones/ai-suggest-status` y `/mining-status` (Redis 30s)

- `ai-suggest-status`: cache 30s, key `correcciones:ai_suggest_status`.
- `mining-status`: cache 30s, key `correcciones:mining_status`.
- `ai-suggest-settings`: cache 60s (settings cambian raramente).
- Resultado esperado: warm <50ms cada uno.

### 3. Cache del listado de Admin Storages (Redis 60s)

- `StorageProviderController::index()` devuelve lista de storages + métricas agregadas.
- Cache 60s + invalidación en:
  - Toggle de storage (`ApiTranscriptorController::toggleStorage`).
  - Mutación de `storage_providers.base_path` o `transcription_enabled`.
  - `StorageFunnelService::invalidate()` ya cubre el funnel; añadir forget de la nueva key.
- Resultado esperado: `/admin/storages` cold 1.2s → warm <100ms.

### 4. Tuning de `indexData` en API Transcriptor (opcional, requiere análisis)

- La iteración sobre 190 storages es PHP puro, no SQL. Reduce a 1 query batch para los storages + 1 query batch para `cantidadFor` + cache lookups para resolveRootId.
- Estimación: warm 869ms → 500ms.

## Capabilities

### New Capabilities
- `avisos-scan-status-cache`: el endpoint `GET /ia/avisos-inteligentes/scan` SHALL responder en menos de 100 ms en cache caliente y SHALL invalidarse ante cualquier mutación que afecte el conteo de pendientes. TTL default 60 s.
- `correcciones-status-cache`: los endpoints `GET /ia/correcciones/ai-suggest-status` y `/mining-status` SHALL responder en menos de 50 ms warm. TTL 30 s.
- `admin-storages-index-cache`: el endpoint `GET /admin/storages` SHALL responder en menos de 200 ms warm. TTL 60 s.

## Impact

- `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (3-5 lineas en scanStatus + invalidaciones).
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (3-5 lineas en 3 métodos).
- `app/app/Http/Controllers/StorageProviderController.php` (envolver `index()` en Cache::remember).
- `app/app/Services/Ia/AvisosScanService.php` (helpers privados opcionales para cache miss).
- `app/app/Services/Ia/WatermarkReconciler.php` (forget `avisos:scan_status` en bump).
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::toggleStorage()` (forget de las nuevas keys).
- Sin migración. Sin breaking change.

## Non-goals

- No se rediseña el template de Avisos Inteligentes ni de API Transcriptor.
- No se cambia el HTML render path (Blade/SSR).
- No se tocan los módulos 5-19 (latencia aceptable).
- No se introduce pre-warming (complejidad operativa).

## Medición esperada (post-implementación)

| Módulo | WARM actual | WARM esperado |
|--------|-------------|---------------|
| Avisos Inteligentes | 11.3s | **1.5s** |
| Correcciones | 3.6s | **1.5s** |
| API Transcriptor | 3.4s | **2.5s** (sin tocar HTML render) |
| Admin Storages | 2.1s | **0.9s** |
| **Total navegación admin típica (4 tabs)** | **20.4s** | **6.4s** |

## Riesgos

| Riesgo | Mitigación |
|--------|-----------|
| Stats stale en Avisos | El operador recarga la página para ver cambios; 60 s es aceptable. |
| Toggle de storage no invalida correcciones | Correcciones no depende de storages directamente; cache separado. |
| Race en coverage stats al mutar keywords | `Cache::lock` si fuera necesario; evaluamos en implementacion. |
