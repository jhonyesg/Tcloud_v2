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
- **Facades**: todo facade (`DB`, `Cache`, `Log`, `Storage`, `Mail`, etc.)
  usado dentro de `app/app/Services/*.php` debe tener su
  `use Illuminate\Support\Facades\X;` correspondiente. Sin el import, PHP
  resuelve `X` dentro del namespace actual (`App\Services\X`) y lanza
  `Class "App\Services\X" not found` → HTTP 500. Referencia: regresión del
  2026-09-06 documentada en
  `openspec/changes/fix-storage-sync-missing-db-facade-import/` (caso
  `StorageSyncService::isFileLinked()` con `DB`). Harness de regresión:
  `tests/harness_storage_sync_is_file_linked.php`.

## Rollback del change `optimize-transcriptor-dispatch-throughput`

Tres pasos para volver al estado anterior si la feature rompe en producción:

```bash
# 1. Revertir la migración (las cuatro columnas son nullable, drop limpio).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
php artisan migrate:rollback --step=1

# 2. Revertir el merge (mantiene la migracion revertida en sync con el codigo).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash-de-la-feature>

# 3. Reiniciar los workers para que vuelvan a la clase anterior de
#    TranscriptionTickCommand (cachean la definicion en memoria).
systemctl restart 'tcloud-transcription-batch-*'
```

**Freno de emergencia alternativo** (sin deploy): `dispatch_paused=true` en
`system_settings` o `TRANSCRIPTOR_DISPATCH_PAUSED=true` en `.env` deja el
descubrimiento corriendo pero corta el envio. El siguiente tick NO encola,
los endpoints de envio devuelven HTTP 423, y el resto del sistema sigue
funcionando. Es el interruptor de seguridad antes de decidirse por el
rollback completo.

## Rollback del change `fix-transcriptor-batch-bg-launcher`

Tres pasos para volver al estado anterior si el botón "Escanear storages"
vuelve a fallar en producción. El cambio es NO disruptivo: solo toca el
trait `RunsBackgroundCommands` y un controller. No hay migración de BD
involucrada y los workers supervisord (`tcloud-transcription-batch-*`)
no necesitan reinicio — el bug original era 100% en el wrapper de
lanzamiento del controller, no en los workers de Redis.

```bash
# 1. Revertir el merge (un solo archivo crítico: el trait).
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
git revert <commit-hash-de-la-feature>

# 2. Recargar PHP-FPM para que el trait revieja quede en memoria de
#    los workers. Sin esto, PHP-FPM sigue corriendo la versión cacheada
#    en opcode del trait viejo.
#    Ajustar al sistema de gestión de PHP-FPM propio del servidor:
systemctl reload php84-php-fpm    # o el equivalente según el entorno
# (alternativa equivalente): `nginx -s reload` + reload del pool FPM

# 3. Verificación rápida post-rollback (sanity, no obligatoria):
#    Click en "Escanear storages" manualmente en la UI. Si el bug
#    ORIGINAL (modal colgado en "Iniciando proceso en background...")
#    vuelve a aparecer, el rollback no se aplicó completo — revisar
#    que `git revert` haya tocado
#    app/app/Http/Controllers/Concerns/RunsBackgroundCommands.php.
```

**Por qué este rollback es seguro**:
- Los otros dos callers del trait (`CorreccionesController::apply`,
  `AvisosInteligentesController::scan`) ya respetaban el contrato
  antes del cambio (pasaban `$cmd` sin `&` ni redirección). El
  revert NO rompe esas rutas — siguen funcionando porque la firma
  del trait con `?string $logFile = null` y `?string $cacheKey = null`
  es backward-compatible (Laravel/IDE ignoran los args extra en
  callers que no los pasan).
- Si el operador quiere volver a usar "Escanear storages" con el
  revert aplicado, el bug original reaparece (modal colgado). Eso
  ES la señal de que el revert funcionó: significa que el trait
  viejo está activo y el controller viejo (que le pasaba `&` al
  `$cmd`) está corriendo. En ese caso, **no seguir usando el botón
  manual** y aplicar el fix nuevamente cuando se diagnostique la
  regresión.

**Freno de emergencia alternativo** (sin deploy): mientras se
diagnostica, el operador puede ejecutar el escaneo desde CLI
mientras el botón UI está roto:

```bash
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
/usr/bin/php84 artisan transcription:scan-and-submit --days=0 --batch=200
```

El cron `TranscriptionTickCommand` también corre el mismo comando
in-process (no vía `execBackground`), por lo que el escaneo
automático sigue funcionando aunque el botón manual esté roto.
