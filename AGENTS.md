# AGENTS.md

Convenciones operativas y de código para agentes (Kilo u otros) que trabajen en TCloud.

## Regla crítica: sesiones en Redis

Toda consulta o eliminación de claves de sesión DEBE pasar por:
- `SessionService::sessionExistsInRedis($sid)`
- `SessionService::sessionRedisKey($sid)` (privado, pero las pruebas deben
  validar la lógica vía el método público)
- `SessionService::killSession($record)`

**Nunca** usar `Redis::connection('cache')` ni `Redis::connection('default')`
directamente para sesiones. Las sesiones reales viven en `Redis::connection('session')`
(DB lógica configurable vía `REDIS_SESSION_DB`, default `2`) bajo la clave
`{database.redis.options.prefix}{cache.prefix}{session_id}` (ej.
`tcloud_tcloud_cache_abc123`).

**Cuidado con `config/session.php`**: `session.store` (que apunta a un
`Cache::store()` registrado en `config/cache.php::stores`) y `session.connection`
(que se pasa a `Redis::setConnection()` internamente) son cosas DISTINTAS.
Solo se modifica `session.connection` para apuntar a la nueva conexión
`session`. `session.store` se deja en null (default = `redis`). Setear
`session.store = 'session'` rompe la app con HTTP 500 porque no existe un
cache store con ese nombre (verificado en deploy 2026-09-05).

Razón histórica: el bug original (2026-09-05) hacía que `cleanOrphans()`
borrara el 100% de las filas de `user_sessions` cada 30 minutos, causando
logout silencioso a todos los usuarios — incluyendo admins con
`session_lifetime_minutes=0` (nunca expira). El bug estaba en
`sessionExistsInRedis()` y `killSession()` apuntando a la conexión `cache`
(DB 1) cuando las sesiones viven en DB 0 (vía override de
`SessionManager::createRedisDriver()`).

## Helpers disponibles

| Helper | Uso |
|--------|-----|
| `SessionService::sessionExistsInRedis($sid): bool` | ¿La sesión existe en Redis? |
| `SessionService::killSession(UserSession $s): void` | Borrar sesión (DB + Redis) |
| `SessionService::killAllUserSessions(User $u, ?string $exceptSid = null): int` | Borrar todas las sesiones de un usuario |
| `SessionService::cleanOrphans(bool $dryRun = false): int` | Borrar user_sessions huérfanas (con guardarraíl de ratio) |
| `SessionService::cleanExpired(): int` | Borrar user_sessions expiradas |
| `SessionService::getEffectiveLifetimeMinutes(User $u): int` | Lifetime efectivo (per-user override o global) |
| `SessionService::getEffectiveMaxSessions(User $u): int` | Max sesiones simultáneas |

## Monitoreo operativo

Detectar anomalías en la limpieza de sesiones:

```bash
# ¿Cuántas sesiones se escanearon vs cuántas se borraron en las últimas 24h?
grep "sessions.cleanup" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log | tail -50

# ¿Hubo abortes por purga masiva?
grep "sessions.cleanup.aborted_mass_delete" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log

# ¿Cuántas sesiones activas tenemos ahora mismo?
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage \
  -c "SELECT COUNT(*) FROM user_sessions WHERE expires_at IS NULL OR expires_at > now();"

# ¿Cuántas sesiones hay realmente en Redis (DB de sesiones)?
redis-cli -a 'Clouding2026!Redis' -n 2 KEYS 'tcloud_tcloud_cache_*' | wc -l
```

Síntomas de problemas:
- `sessions.cleanup.aborted_mass_delete` con ratio alto: cleanOrphans detectó
  que estaba a punto de borrar casi todas las sesiones. Investigar antes de
  aprobar un nuevo deploy.
- `user_sessions` cayendo a 0 cíclicamente: el bug original — significa que
  `sessionExistsInRedis` apunta a la conexión incorrecta de nuevo. NO
  desplegar más cambios hasta arreglar.
- `intervals_too_aggressive` repetido: alguien bajó el intervalo de
  `sessions_cleanup_interval_minutes` por debajo de 5 min.

## Operaciones Redis

### Migración de la DB de sesiones (DB 0 → DB 2)

Si necesitas migrar sesiones legacy desde DB 0 (donde vivían antes del fix)
hacia DB 2 (la nueva conexión dedicada):

```bash
# 1. Verificar cuántas claves hay en DB 0 (sessions antiguas)
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | wc -l

# 2. Copiar una a una (RENAME preserva el TTL)
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -I{} redis-cli -a 'Clouding2026!Redis' -n 2 RENAME "{}" "{}"

# 3. Validar que ahora DB 2 tiene las sesiones
redis-cli -a 'Clouding2026!Redis' -n 2 KEYS 'tcloud_tcloud_cache_*' | wc -l

# 4. Esperar 24h para confirmar estabilidad, luego purgar DB 0
redis-cli -a 'Clouding2026!Redis' -n 0 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -r redis-cli -a 'Clouding2026!Redis' -n 0 DEL
```

⚠️ **Crítico**: NO borrar las claves de DB 0 antes del paso 2, o todos los
usuarios quedan deslogueados.

### Rollback (si el deploy tumba sesiones)

```bash
# 1. Revertir el merge en git
git revert <commit-hash>

# 2. Mover sesiones de vuelta a DB 0
redis-cli -a 'Clouding2026!Redis' -n 2 --scan --pattern 'tcloud_tcloud_cache_*' \
  | xargs -I{} redis-cli -a 'Clouding2026!Redis' -n 0 RENAME "{}" "{}"
```

## Convenciones de código

- **PHP**: ver `phpcs.xml` / `pint.json` (si existen). PSR-12 por defecto.
- **Tests**: ver `phpunit.xml`. Tests de integración usan el harness
  `tests/harness_*.php` que ejecuta contra PostgreSQL y Redis reales.
- **Migrations**: prefijo de fecha, ej. `2026_05_13_100002_add_session_fields_to_users_table.php`.
- **OpenSpec**: specs en `openspec/specs/`, cambios activos en `openspec/changes/`.
- **Auth**: SIEMPRE `session('user_id')`, NUNCA `auth()->user()`.
- **Servidor**: NO Vercel ni Supabase. nginx + PHP-FPM sobre cloud.mediaserver.com.co.
