## Why

`SessionService::sessionRedisKey()` componía `database.redis.options.prefix + cache.prefix + $sid` antes de pasar la clave a `Redis::connection('session')->{exists,del}(...)`. Pero phpredis (con `OPT_PREFIX = 'tcloud_'`) ya antempone el `redis.prefix` automáticamente a cualquier comando. Resultado: clave salía al servidor con triple prefijo (`tcloud_tcloud_tcloud_cache_<sid>`) y nunca encontraba la clave real, que está en `tcloud_tcloud_cache_<sid>` (doble). `killSession()` borraba la fila de `user_sessions` pero dejaba la sesión viva en Redis; los usuarios conservaban su cookie `tcloud_session` apuntando a una sesión Redis válida. `cleanOrphans()` jamás detectaba huérfanas porque `sessionExistsInRedis()` siempre devolvía `false`. Detectado el 2026-09-15 al forzar logout global: las filas en BD se vaciaron pero 474 claves Redis persistieron.

## What Changes

- `app/app/Services/SessionService.php` línea 27-32: `sessionRedisKey()` deja de componer `redis.prefix` (lo aplica phpredis). Devuelve solo `cache.prefix . $sid`.
- Comentario inline explica la invariante: phpredis aplica `OPT_PREFIX`; la capa PHP debe aportar solo lo que falte.
- `tests/harness_session_service_kill_redis_key.php`: harness de regresión ejercita `SessionService::killSession()` contra sesiones Laravel reales y compara el conteo `DBSIZE` de Redis DB 2 antes/después.

## Non-goals

- No migrar sesiones entre DBs de Redis.
- No tocar `session-cleanup-safety`, `session-redis-connection` ni otros specs adyacentes — su comportamiento ya está descrito correctamente.
- No reintroducir TTLs, ni cambiar claves prefijo globales.
- No migrar sesiones huérfanas existentes (el force-logout del 2026-09-15 ya las limpió con un FLUSHDB manual documentado en `tasks.md`).

## Capabilities

### New Capabilities

- `session-redis-prefix-single-construction`: garantiza que `SessionService::killSession()` y `SessionService::sessionExistsInRedis()` apunten a la **misma** clave Redis donde Laravel guarda la sesión (un solo `cache.prefix` aplicado por la capa PHP, dejando que phpredis aplique el `redis.options.prefix`). Verificable ejecutando una sesión real, llamando `killSession` y comprobando que `DBSIZE` de la DB `session` decrementa en uno.

### Modified Capabilities

- (vacío)

## Impact

- **Archivos**: `app/app/Services/SessionService.php` (3 líneas modificadas — 1 borrada, 1 reestructurada, comentario añadido).
- **Tests**: `tests/harness_session_service_kill_redis_key.php` (nuevo, mismo patrón que `harness_mis_avisos_clip_limit.php`, `harness_dashboard_partials.php`).
- Sin migración BD. Sin cambio de rutas. Sin cambio de headers.
- Sin cambio de contrato: las clases externas siguen viendo la misma firma; solo el cuerpo de un método privado.
- Riesgo muy bajo: la corrección alinea la composición con el formato real de las claves que escribe Laravel. Si el formato cambia en el futuro (p.ej. subimos a Laravel 11 con otra convención de prefijo) el harness detectará la regresión.
