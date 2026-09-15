# avisos-scan-status-cache Specification

## Purpose
TBD - created by archiving change 2026-09-13-perf-audit-and-improve. Update Purpose after archive.

## Requirements

### Requirement: Cache de `/ia/avisos-inteligentes/scan` con TTL 60s

El sistema SHALL cachear la respuesta combinada de `GET /ia/avisos-inteligentes/scan` en Redis con TTL configurable (default 60 s). La key SHALL ser `avisos:scan_status` y SHALL incluir:
- `settings`: configuracion actual del scan automatico.
- `last_run`: ultima corrida (objeto).
- `runs`: ultimas 10 corridas (array).
- `pending_estimate`: estimacion de pendientes segun filtros del query.
- `total_pending_estimate`: estimacion total (sin ventana temporal).

El TTL SHALL ser legible desde `SystemSetting('avisos_scan_status_cache_ttl', 60)`. Rango valido `[0, 600]`. Valor `0` = bypass.

#### Scenario: Cache hit dentro del TTL
- **GIVEN** la key `avisos:scan_status` existe y fue poblada hace menos de 60 s
- **WHEN** el navegador hace `GET /ia/avisos-inteligentes/scan`
- **THEN** el sistema retorna el JSON cacheado SIN ejecutar `estimate()`, `recentRuns()`, `lastRun()` ni `settings()`
- **AND** el tiempo de respuesta es `< 100 ms`

#### Scenario: Cache miss fuera del TTL
- **GIVEN** la key `avisos:scan_status` no existe o expiró
- **WHEN** el navegador hace `GET /ia/avisos-inteligentes/scan`
- **THEN** el sistema ejecuta los compute (estimaciones + recent runs) y popula el cache

#### Scenario: Invalidacion ante mutacion de transcripcion
- **WHEN** una `Transcription` se crea, completa, falla o cambia de estado
- **THEN** el sistema invalida `avisos:scan_status` via `Cache::forget(...)`

#### Scenario: Invalidacion en WatermarkReconciler
- **WHEN** se ejecuta cualquier `CacheEpoch::bump()` en `WatermarkReconciler`
- **THEN** el sistema invalida `avisos:scan_status` simultaneamente

### Requirement: Bypass con TTL=0

Si `SystemSetting('avisos_scan_status_cache_ttl') = 0`, el sistema SHALL ejecutar el compute directo cada vez (sin cache) y NO escribir en Redis.

#### Scenario: Bypass activo
- **GIVEN** `SystemSetting::set('avisos_scan_status_cache_ttl', '0')` (freno de emergencia)
- **WHEN** se invoca el endpoint
- **THEN** el sistema NO consulta ni escribe cache
