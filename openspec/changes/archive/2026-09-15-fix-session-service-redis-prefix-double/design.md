## Context

El `SessionService` de TCloud es el único punto de mutación de sesiones (DB + Redis). El handler de sesión de Laravel escribe en Redis a través de la conexión `database.redis.session` con dos prefijos sucesivos:
- Capa Laravel/Cache: `cache.prefix = 'tcloud_cache_'` antepuesto al session_id.
- Capa phpredis: `OPT_PREFIX = 'tcloud_'` configurado en `database.redis.options.prefix` (vía PhpRedis).

Clave final en Redis: `tcloud_tcloud_cache_<sid>` (doble prefijo, una capa por origen).

El helper privado `sessionRedisKey()` componía **tres** capas: `database.redis.options.prefix + cache.prefix + $sid`. Al pasar esa clave a métodos como `Redis::connection('session')->exists(...)`, phpredis aplicaba **una cuarta capa** automática (`tcloud_`), generando triple prefijo. Resultado: `exists`/`del` siempre apuntaban a una clave inexistente y todo el flujo era no-op silencioso para Redis.

## Goals / Non-Goals

**Goals:**
- Eliminar el doble conteo manual del `redis.options.prefix`.
- Verificar con harness que la operación `killSession` realmente decrementa `DBSIZE` de la DB de sesiones.
- Mantener la superficie pública de `SessionService` intacta.

**Non-Goals:**
- No tocar prefijo global de Redis, ni TTL, ni esquema de DB.
- No migrar las 474 sesiones huérfanas históricas (ya borradas con `FLUSHDB` documentado en `tasks.md` de este change).
- No refactorizar a `Cache::store('redis')` para evitar acoplar a convenciones de Laravel que cambian entre versiones (el helper local es más estable).
- No tocar `session-redis-connection` ni `session-cleanup-safety` (sus contratos siguen vigentes; este change los refuerza).

## Decisions

### Decisión 1 — Eliminar la composición redundante de `redis.options.prefix`

El cambio aceptado es: `sessionRedisKey()` devuelve `cache.prefix . $sid`. PhpRedis aplica `OPT_PREFIX` automáticamente; no hay que prefijar dos veces. Verificado experimentalmente con un script que crea una sesión Laravel real, llama `killSession()` y compara `DBSIZE` antes/después — antes del fix el `DBSIZE` no cambia; después del fix decrementa en uno por sesión matada.

**Por qué no usar `Cache::store('redis')` directamente**: ese store internamente vuelve a prefijar con `cache.prefix`, lo que exigiría pasar la clave cruda y romper el aislamiento. Mantener el helper local minimiza el blast radius y deja trazabilidad.

**Por qué no deshabilitar `OPT_PREFIX` en la conexión**: sería invasivo y rompería otros callers que sí dependen del prefijo automático.

### Decisión 2 — Harness como prueba de regresión activa

`tests/harness_session_service_kill_redis_key.php` sigue el patrón de `tests/harness_mis_avisos_clip_limit.php`:
- Tag único `hsr_<8-hex>` para trazabilidad de filas insertadas.
- Cleanup defensivo al inicio por si una corrida anterior dejó basura.
- Helpers `h_ok` / `h_fail` / `h_section` / `h_check` con `$failures` global y exit 0/1.

Aserciones que ejecuta el harness:

1. **Estado limpio**: `DBSIZE` de Redis DB `session` se anota.
2. **Crear sesión real**: `Session::start()` + `Session::put()` + `Session::save()` + insert manual en `user_sessions`.
3. **Verificar existencia**: `DBSIZE` debe haber aumentado al menos en uno y `sessionExistsInRedis($sid) === true`.
4. **`killSession`**: invoca el método.
5. **Post-condiciones**: fila de DB borrada, `sessionExistsInRedis === false`, `DBSIZE` decrementado en uno.
6. **`killAllUserSessions`**: repite con 3 sesiones para un usuario `Massmedios` sintético; verifica que `killed == 3` y que `DBSIZE` decrementa exactamente en 3.

Si el helper retrocede al triple prefijo, las aserciones 5 y 6 fallan (Redis nunca decrementa).

### Decisión 3 — Fix sin cambio de comportamiento externo

Solo se modifica el cuerpo de un método privado. La interfaz pública (`killSession`, `killAllUserSessions`, `cleanExpired`, `cleanOrphans`) queda idéntica. Cero impacto en routes, controllers, modelos.

## Risks / Trade-offs

- **[Riesgo] Si en el futuro Laravel cambia la convención de prefijo de Cache** (p.ej. Laravel 11 separa `cache.prefix` por store), el helper podría quedar desincronizado → Mitigación: el harness detecta cualquier divergencia. Si rompe, el fix es de 2 líneas en el helper.
- **[Trade-off] Las sesiones huérfanas históricas NO se migraron** → aceptable: el force-logout manual del 2026-09-15 ya las limpió. Documentado en `tasks.md`.
- **[Riesgo] Redis cluster**: si en el futuro se cambia a Redis Cluster, `OPT_PREFIX` semánticamente se vuelve clave hashing y este fix podría necesitar revisión → no aplica ahora; abrir change aparte cuando se introduzca.

## Migration Plan

Sin migración de BD. Sin cambio de rutas. Sin cambio de headers. Sin cambio de variables de entorno.

**Deploy**: merge directo, sin pasos especiales. Las sesiones activas se mantendrán intactas (no se invalida nada) — solo se corrige cómo se leen/borran en el futuro.

**Rollback**: `git revert <commit>`. Riesgo: las sesiones creadas después del rollback pueden quedarse vivas en Redis al ser matadas. Para un rollback limpio: `redis-cli -n 2 FLUSHDB` en DB sesiones y `TRUNCATE user_sessions` (esto NO afecta a cache DB 1).

**Verificación post-deploy**:
1. Correr el harness: `cd app && php tests/harness_session_service_kill_redis_key.php` → debe retornar `exit 0`.
2. Manualmente: en un navegador activo, cerrar sesión desde el menú → verificar que la cookie `tcloud_session` deja de funcionar (DevTools network 302 a `/login` con `X-Session-Expired: 1`).
3. `redis-cli -n 2 KEYS '*' | wc -l` tras cerrar sesión desde UI → no debe crecer.
