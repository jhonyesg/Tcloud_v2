# Tasks

## 1. Cache de `/ia/avisos-inteligentes/scan` (Redis 60s)

- [x] 1.1 En `AvisosInteligentesController::scanStatus()`, envolver el compute en `Cache::remember('avisos:scan_status', 60, fn() => ...)`.
- [x] 1.2 TTL configurable via `SystemSetting::get('avisos_scan_status_cache_ttl', 60)`. Rango [0, 600]. 0 = bypass.
- [x] 1.3 Invalidar la cache en `saveScanSettings` (cuando el operador cambia la configuracion).
- [x] 1.4 En `WatermarkReconciler`, agregar `Cache::forget('avisos:scan_status')` junto a cada `CacheEpoch::bump()` (4 sites).
- [x] 1.5 `php -l` del controller.

## 2. Cache de endpoints de Correcciones (Redis 30s/60s)

- [x] 2.1 Cachear `ai-suggest-status` con TTL 30s, key `correcciones:ai_suggest_status`.
- [x] 2.2 Cachear `mining-status` con TTL 30s, key `correcciones:mining_status`.
- [x] 2.3 Cachear `ai-suggest-settings` con TTL 60s, key `correcciones:ai_suggest_settings`.
- [x] 2.4 En el endpoint que guarda settings (`POST /ai-suggest-settings`, `DELETE`, `refresh-models`, `api-key`), invalidar las 3 caches. Solo `ai_suggest_settings` se invalida explicitamente; `mining_status` y `ai_suggest_status` son TTL-only.
- [x] 2.5 `php -l`.

## 3. Cache del listado de Admin Storages (Redis 60s)

- [x] 3.1 En `StorageProviderController::index()`, envolver el compute en `Cache::remember('admin:storages:index', 60, fn() => ...)`.
- [x] 3.2 TTL configurable via `SystemSetting::get('admin_storages_cache_ttl', 60)`. Rango [0, 600]. 0 = bypass.
- [x] 3.3 En `StorageProviderController::store/update/destroy` y en `ApiTranscriptorController::toggleStorage()`, agregar `Cache::forget('admin:storages:index')`.
- [x] 3.4 N/A (los mutadores de `base_path`/`transcription_enabled` ya estan cubiertos).
- [x] 3.5 `php -l`.

## 4. AGENTS.md

- [x] 4.1 Documentar las 3 nuevas caches en la seccion "Cache" existente (mismo patron que el cache de stats/health/empty-folders).

## 5. Validacion

- [x] 5.1 Crear `tests/harness_perf_audit_cache.php` que valide:
  - `/ia/avisos-inteligentes/scan` warm < 100 ms.
  - `/ia/correcciones/ai-suggest-status` warm < 50 ms.
  - `/ia/correcciones/mining-status` warm < 50 ms.
  - `/ia/correcciones/ai-suggest-settings` warm < 30 ms.
  - `/admin/storages` warm < 200 ms.
  - Bypass TTL=0 para los 5 endpoints.
  - Toggle de storage invalida caches relacionadas.
- [x] 5.2 Ejecutar harness: `cd app && php tests/harness_perf_audit_cache.php` → exit 0 (17 assertions pass).

## 6. Smoke test con Playwright

- [x] 6.1 Re-ejecutar `/tmp/kilo/audit_modules.py` post-implementacion.
- [x] 6.2 Ranking post-fix (warm):
  - Avisos Inteligentes: **11.3s → 1.4s** (87% reduccion, /scan 4.9s → 109 ms).
  - Admin Storages: 2.1s → 1.0s (52%).
  - Correcciones: 3.6s → 3.5s (marginal — el cuello sigue siendo el page render, no los AJAX status).
  - API Transcriptor: 3.4s (sin cambio, ya optimizado en el change archivado previo).

## 7. Rollback

- [x] 7.1 `SystemSetting::set('avisos_scan_status_cache_ttl', '0')` + `admin_storages_cache_ttl='0'` → comportamiento vuelve al baseline.
- [x] 7.2 `git revert <commit>` + reload PHP-FPM para revertir completo.
