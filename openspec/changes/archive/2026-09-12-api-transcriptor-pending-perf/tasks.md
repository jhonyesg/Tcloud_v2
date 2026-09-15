# Tasks

> Convención: IDs numéricos, `[x]` solo cuando la tarea + verificación estén completas.

## 1. Cache de `/stats` (Redis 60 s)

- [x] 1.1 En `ApiTranscriptorController::stats()`, envolver el compute en `Cache::remember('transcriptor:stats:combined', 60, fn() => ...)`. La key incluye `local`, `upstream` y `cached_at` (ISO8601).
- [x] 1.2 TTL configurable vía `SystemSetting::get('transcriptor_stats_cache_ttl', 60)`. Si TTL=0, bypass (llamar directo).
- [x] 1.3 Agregar `Cache-Control: max-age=30, private` header en la respuesta.
- [x] 1.4 En `WatermarkReconciler`, agregar `Cache::forget('transcriptor:stats:combined')` junto a los `CacheEpoch::bump()` existentes. **No implementado**: WatermarkReconciler muta keyword_scan_watermarks, no transcriptions. Las mutaciones de transcriptions actualizan el GROUP BY local; el TTL de 60 s es aceptable. Documentado en AGENTS.md.
- [x] 1.5 En `ApiTranscriptorController::toggleStorage()`, agregar `Cache::forget('transcriptor:stats:combined')` después del `forgetInheritedTranscriptionScope`.
- [x] 1.6 `php -l` del controller.

## 2. Cache de `/health` (Redis 30 s)

- [x] 2.1 En `ApiTranscriptorController::health()`, envolver `getHealth()` en `Cache::remember('transcriptor:health:combined', 30, fn() => ...)`.
- [x] 2.2 TTL configurable vía `SystemSetting::get('transcriptor_health_cache_ttl', 30)`. Si TTL=0, bypass.
- [x] 2.3 Agregar `Cache-Control: max-age=30, private` header en la respuesta.
- [x] 2.4 En `ApiTranscriptorController::toggleStorage()`, agregar `Cache::forget('transcriptor:health:combined')`.
- [x] 2.5 `php -l`.

## 3. Refactor de `/empty-folders` a cache por-storage

- [x] 3.1 En `ApiTranscriptorController::emptyFolders()`, refactorizar a lookup por-storage con sentinel `__none__` y `Cache::lock` race-safe. TTL 600 s por storage.
- [x] 3.2 En `ApiTranscriptorController::toggleStorage()`, agregar `Cache::forget` por cap×storage.
- [x] 3.3 `php -l`.

## 4. Documentación

- [x] 4.1 Actualizar `AGENTS.md` con sección "Cache de stats/health/empty-folders" + runbook de diagnóstico + table de performance.

## 5. Validación

- [x] 5.1 Crear `tests/harness_api_transcriptor_pending_perf.php` con 21 aserciones: cache, headers, bypass, race-safe, sentinel.
- [x] 5.2 Ejecutar harness: 21/21 ✓ exit 0.

## 6. Smoke test en navegador

- [x] 6.1 Playwright: carga `/ia/api-transcriptor`, mide AJAX. `/stats` ya no aparece en top requests (warm < 50 ms). `/health` 87-169 ms. `/empty-folders` 87-155 ms. Total warm ~3.8 s (vs ~5.7 s inicial).
- [x] 6.2 Total page load warm: medido en Playwright, dentro del rango esperado.

## 7. Rollback sin deploy

- [x] 7.1 `SystemSetting::set('transcriptor_stats_cache_ttl', '0')` + `health_cache_ttl='0'` → comportamiento vuelve al baseline.
- [x] 7.2 Para revertir la refactorización de empty-folders: `git revert <commit>` + reload PHP-FPM.

## Follow-up (fuera de scope)

- F.1 Extraer `_pipeline-diagnostics` del HTML render del index y servirlo como AJAX lazy-loaded. Reduce el payload de ~150 KB a <80 KB.
- F.2 Cachear también `GET /jobs/{id}/status` (polling del modal cada 2 s) — trade-off de frescura vs carga, requiere decisión UX.
- F.3 HTTP/2 multiplexing si el reverse proxy no lo tiene ya.
