## Why

Después de aplicar el change `2026-09-12-api-transcriptor-pending-perf` (archivado), `/stats` quedó con TTL 60s y `/health` con TTL 30s. El operador recarga `/ia/api-transcriptor` después de navegar 1-3 minutos por otros módulos — al volver, esos caches ya expiraron y el reload dispara cold hits (`/stats` 200-755ms con HTTP upstream + GROUP BY, `/health` 200-500ms con HTTP upstream). El operador percibe "lento al volver".

Reproducido en Playwright (sesión jsuarez, 2026-09-13): tras esperar 200s, reload de `/ia/api-transcriptor`:

```
/stats       cold 755ms → warm 19ms  (TTL 60s expiraba; nuevo 300s)
/health      cold 488ms → warm 71ms  (TTL 30s expiraba; nuevo 120s)
```

## What Changes

- `ApiTranscriptorController::resolveStatsCacheTtl()` default: **60 → 300** (5 min). Operador que navega un rato y recarga ve stats warm sin esperar al upstream.
- `ApiTranscriptorController::resolveHealthCacheTtl()` default: **30 → 120** (2 min). Health check del upstream se refresca cada 2 min sin stressing al transcriptor.
- Limpieza: `DELETE FROM system_settings WHERE key IN ('transcriptor_stats_cache_ttl', 'transcriptor_health_cache_ttl')` para que los defaults nuevos apliquen (los valores legacy en BD anulaban los defaults).
- `tests/harness_api_transcriptor_pending_perf.php`: cleanup que preserva los valores originales o borra los defaults (antes sobreescribia a 60/30).
- `AGENTS.md`: tabla de TTLs actualizada con los nuevos defaults + justificación.

## Capabilities

Esta es una **modificación** del comportamiento existente en `transcriptor-stats-health-cache`. La spec queda así:
- `/stats` SHALL responder en menos de 50 ms en cache caliente con TTL default 300 s.
- `/health` SHALL responder en menos de 50 ms en cache caliente con TTL default 120 s.

## Impact

- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` (2 lineas, default constants)
- `app/tests/harness_api_transcriptor_pending_perf.php` (~10 lineas, cleanup)
- `AGENTS.md` (tabla de TTLs)
- Sin migración. Sin breaking change.
- DB: una sola fila eliminada (`system_settings.transcriptor_stats_cache_ttl` legacy).

## Non-goals

- No se cambia la cache del scope (300 s) ni la de empty-folders (600 s) — siguen iguales.
- No se cambia el bypass con TTL=0 — sigue funcionando.
- No se rediseña el contrato de invalidación en toggleStorage.
