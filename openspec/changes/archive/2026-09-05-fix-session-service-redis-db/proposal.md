## Why

El `SessionService` consulta y elimina sesiones en Redis usando `Redis::connection('cache')` (DB 1), pero Laravel guarda las sesiones reales en `Redis::connection('default')` (DB 0) por la lógica de `SessionManager::createRedisDriver()` que hace override de la conexión. Resultado: `sessionExistsInRedis()` siempre devuelve `false` y `killSession()` nunca borra el payload de Redis. Esto provoca que el cron `cleanOrphans` (cada 30 min) borre TODAS las filas de `user_sessions` de TODOS los usuarios, generando logout silencioso recurrente — incluyendo al admin que tiene `session_lifetime_minutes=0`. La configuración `session_lifetime_minutes=0` sí se respeta en BD, pero la cookie de sesión se pierde igualmente por la purga incorrecta.

## What Changes

- Corregir `SessionService::sessionExistsInRedis()` para apuntar a la conexión donde Laravel realmente guarda las sesiones (`default` / DB 0) con el prefijo completo `redis_options_prefix + cache_prefix + session_id`.
- Corregir `SessionService::killSession()` para borrar la clave de sesión en la misma conexión correcta.
- Añadir una conexión Redis dedicada `session` en `config/database.php` para evitar la confusión entre `default` y `cache`, y reutilizarla desde `config/session.php`.
- Endurecer `cleanOrphans()` con guardarraíles: dry-run opcional, métrica/log de huérfanos detectados, y opción de mantener N respaldos.
- Añadir tests automatizados que validen que un usuario con `session_lifetime_minutes=0` sobrevive al paso de `cleanOrphans` (regresión exacta del bug reportado).

## Capabilities

### New Capabilities
- `session-redis-connection`: Conexión Redis dedicada para sesiones, contract del helper `sessionExistsInRedis()` y del cleanup correcto entre BD y Redis.
- `session-cleanup-safety`: Guardarraíles del cron `sessions:cleanup` (cleanOrphans + cleanExpired): idempotencia, dry-run, métricas, umbral de seguridad para evitar purgas masivas accidentales.

### Modified Capabilities
- `session-tracker-cache`: Reemplazar el requirement contradictorio sobre `Redis::connection('cache')` (incorrecto, DB 1) por el uso correcto de la nueva conexión `session` (DB 0). Las sessions MUST vivir en una conexión dedicada y los helpers MUST centralizarse para evitar futuras discrepancias.
- `user-sessions-coherence`: Aclarar que `killSession` y `cleanOrphans` operan sobre la conexión Redis donde Laravel persistió la sesión (no asumir `cache`).

## Impact

- `app/app/Services/SessionService.php` (líneas 17-22 y 79-93): fix de conexión.
- `app/config/database.php`: nueva entrada `redis.session`.
- `app/config/session.php`: usar `redis.session` como `connection` y como `store`.
- `app/app/Http/Middleware/SessionTracker.php`: sin cambios directos (ya consume `SessionService`).
- `app/routes/console.php`: ajustar el closure del cron para pasar opciones de dry-run/metric y registrar cuántas filas eliminó vs cuántas escaneó.
- `app/app/Services/SessionService.php::cleanOrphans()`: agregar guardarraíl de ratio máximo de borrado (si borra >X% de filas en una corrida, abortar y alertar).
- Nuevos tests en `app/tests/Feature/SessionServiceTest.php` y `app/tests/Unit/SessionCleanOrphansTest.php`.
- Documentación: actualizar `AGENTS.md` y/o `openspec/specs/session-tracker-cache/spec.md` con la verdad empírica (DB 0 vs DB 1).

## Non-goals

- No se cambia el modelo de datos de `user_sessions` ni la semántica de `session_lifetime_minutes`.
- No se migra la sesión a JWT, Sanctum, ni otro mecanismo.
- No se cambia el TTL de Redis (`SESSION_LIFETIME=1440`).
- No se tocan los flujos de `AuthController` ni el keep-alive del frontend (`session-manager.js`).
