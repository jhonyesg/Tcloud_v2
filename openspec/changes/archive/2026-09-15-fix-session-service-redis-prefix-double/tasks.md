## 1. Aplicar el fix en `SessionService`

- [x] 1.1 Modificar `app/app/Services/SessionService.php` `sessionRedisKey()` para devolver solo `cache.prefix . $sid` (sin la composición manual de `redis.options.prefix`).
- [x] 1.2 Añadir comentario inline en el helper explicando la invariante: phpredis aplica `OPT_PREFIX` automáticamente.

## 2. Harness de regresión

- [x] 2.1 Crear `app/tests/harness_session_service_kill_redis_key.php` siguiendo el patrón de `harness_mis_avisos_clip_limit.php` y `harness_dashboard_partials.php`.
- [x] 2.2 Helpers: tag único `hsr_<8-hex>`, funciones `h_ok`/`h_fail`/`h_section`/`h_check`, `$failures` global, exit 0/1.
- [x] 2.3 Cleanup defensivo al inicio (borrar filas previas con `LIKE 'hsr_%'` y claves Redis `*hsr_*`).
- [x] 2.4 Aserción A — sesión individual: `SessionService::sessionExistsInRedis($sid) === true` para sesión viva, `killSession()` la borra, `sessionExistsInRedis === false` después, `DBSIZE` decrementa en uno.
- [x] 2.5 Aserción B — multi-sesión: 3 sesiones para `Massmedios`, `killAllUserSessions($user)` retorna 3, decrementa DBSIZE en 3.

## 3. Validación

- [x] 3.1 Correr el fix manualmente con un script PHP one-shot que crea sesiones y mide DBSIZE antes/después de `killSession` (ya ejecutado durante exploración; ver `tasks.md#8`).
- [x] 3.2 Correr el harness de regresión: `cd app && php tests/harness_session_service_kill_redis_key.php` → exit 0.
- [x] 3.3 Ejecutar `php -l app/app/Services/SessionService.php` → no syntax errors.

## 4. Cierre

- [ ] 4.1 `openspec validate fix-session-service-redis-prefix-double --strict` debe pasar.
- [ ] 4.2 Sincronizar el delta spec a `openspec/specs/session-redis-prefix-single-construction/spec.md` (transformando `ADDED Requirements` → `Requirements`).
- [ ] 4.3 Commit con mensaje: `fix(session): sessionRedisKey ya no duplica el redis.options.prefix (phpredis lo aplica)`.
- [ ] 4.4 Archivar la change: `openspec archive fix-session-service-redis-prefix-double --yes`.

## 5. Documentación del force-logout manual previo

- [x] 5.1 Documentar en `tasks.md#8` que el 2026-09-15 se ejecutó un force-logout manual con `TRUNCATE user_sessions` + `FLUSHDB` Redis DB 2 como medida de recuperación cuando el bug estaba latente. Incluir:
  - 674 filas `user_sessions` eliminadas vía `killAllUserSessions` (no funcionó para Redis — solo limpió DB).
  - 474 (luego 480) claves Redis `tcloud_tcloud_cache_*` eliminadas vía `redis-cli --scan ... | xargs DEL`.
  - 1 `TRUNCATE user_sessions` final + `FLUSHDB` definitivo.
  - 0 sesiones activas al cierre (verificado con Playwright curl devuelve 302 + X-Session-Expired: 1).

## 6. Observación huérfana importante

- [x] 6.1 Mientras el bug estuvo latente, `cleanOrphans()` no funcionaba para detectar sesiones Redis huérfanas (porque `sessionExistsInRedis` devolvía siempre `false`). Esto significa que en producción existían sesiones "fantasma" no contadas. Hoy ya están todas limpias. El guardarraíl de ratio de `cleanOrphans` (abort si ratio > 0.5) habría tapado un mass-delete accidental durante el bug, pero también habría permitido que las huérfanas reales se acumulasen indefinidamente.
- [x] 6.2 Considerar a futuro un change separado para **agregar un task cron de validación de coherencia** que compare `count(user_sessions) vs count(redis_keys_in_db_session)` y emita warning si difieren — independiente de este fix.

## 7. Verificación del harness como detector de regresión

- [x] 7.1 Confirmar que reintroducir la composición de prefijo ($redisPrefix + $cachePrefix) **rompe** el harness. Verificado manualmente durante validación:
  - Con fix: 11/11 aserciones ✓, exit 0.
  - Sin fix (revertido temporalmente): 5 fallos detectados (sessionExistsInRedis false pre-kill, DBSIZE no decrementa en A, DBSIZE no refleja creación en B, sessionExistsInRedis false en C).
  - Con fix restaurado: 11/11 ✓.

## 8. Notas de validación y diagnóstico

- [x] 8.1 La detección del bug ocurrió el 2026-09-15 durante una operación de force-logout solicitada por el usuario. 674 filas `user_sessions` se eliminaron vía `killAllUserSessions()` pero 474-480 claves Redis quedaron vivas porque `SessionService::sessionRedisKey()` componía el prefijo dos veces, enviando al servidor `tcloud_tcloud_tcloud_cache_<sid>` (inexistente) en lugar de `tcloud_tcloud_cache_<sid>` (real).
- [x] 8.2 La limpieza de Redis fue manual: `redis-cli -n 2 --scan --pattern 'tcloud_tcloud_cache_*' | xargs redis-cli -n 2 DEL`. Esto es la única parte operativa que tocó Redis directamente (recuperación de emergencia).
- [x] 8.3 Una vez aplicado el fix y verificado con el harness, la operación normal de cerrar sesión desde la UI vuelve a funcionar correctamente: la cookie `tcloud_session` deja de mapear a una clave Redis viva y el servidor responde `302 + X-Session-Expired: 1` → redirect a `/login`.
