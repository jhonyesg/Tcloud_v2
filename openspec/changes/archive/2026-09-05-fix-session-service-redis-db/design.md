## Context

Hoy `SessionService` consulta y elimina sesiones usando `Redis::connection('cache')` (DB 1). Pero Laravel guarda las sesiones reales en DB 0 porque `SessionManager::createRedisDriver()` hace `setConnection('default')` sobre el cache store de Redis (ver `vendor/laravel/framework/src/Illuminate/Session/SessionManager.php:136-145`). Resultado: `sessionExistsInRedis()` siempre retorna `false`, `cleanOrphans()` borra todas las filas cada 30 minutos y los usuarios sufren logout silencioso recurrente — incluso con `session_lifetime_minutes=0`.

Evidencia empírica (verificada en producción, Sep 5 2026):
- `redis-cli -n 0 KEYS "tcloud_tcloud_cache_*" | wc -l` → 174 sesiones reales
- `redis-cli -n 1 KEYS "tcloud_tcloud_cache_*" | wc -l` → 0 (DB 1 solo tiene `folder_gen:*`)
- `user_sessions` con `expires_at IS NULL` (usuario `jsuarez`, nunca expira): existen en BD pero su `session_id` no aparece en ninguna DB de Redis → logout silencioso garantizado en el próximo request tras `cleanOrphans`

## Goals / Non-Goals

**Goals:**
- Centralizar la lógica de selección de conexión y construcción de clave de sesión en un único par de helpers para que sea imposible divergir entre `sessionExistsInRedis()`, `killSession()`, `countActiveSessions()` y `cleanOrphans()`.
- Hacer explícita la conexión Redis donde viven las sesiones (en lugar del override implícito de `SessionManager::createRedisDriver()`) y separarla físicamente de la conexión `cache` para evitar el conflicto "cache vs session" que produjo este bug.
- Endurecer `cleanOrphans()` contra futuros bugs similares (purga masiva accidental) con guardarraíl de ratio, dry-run, métricas estructuradas e intervalo configurable.
- Añadir tests automatizados que cubran tanto el helper como el escenario end-to-end "usuario con lifetime=0 sobrevive al cron".

**Non-Goals:**
- No se cambia la duración de la cookie ni el TTL en Redis.
- No se migra a Sanctum/JWT/otro mecanismo.
- No se rediseña el flujo de login ni el keep-alive del frontend.

## Decisions

### D1. Conexión Redis dedicada `session` en DB lógica separada

**Decisión**: Añadir `redis.session` en `config/database.php` con `database => env('REDIS_SESSION_DB', 2)` (DB 2, default distinto de `cache` que usa DB 1).

**Por qué**: La causa raíz del bug fue usar la misma conexión `cache` para dos cosas distintas (cache de aplicación y sesiones). Separarlas físicamente:
- Hace imposible volver a confundirlas en código (los nombres `cache` y `session` son autoexplicativos).
- Permite monitorizar/metricar cada DB por separado.
- Permite `maxmemory-policy` por DB si fuera necesario en el futuro.

**Alternativas consideradas**:
- *A. Usar `Redis::connection('default')` (DB 0)* — funciona pero mezcla sesiones con cola/otros usos futuros de `default`. Bajo coste de cambio, peor separación de concerns.
- *B. Definir la conexión pero seguir usando `'default'`* — no resuelve el problema conceptual, solo parchea el síntoma.
- *C. Conexión dedicada `session` (elegida)* — explícita, autodocumentada, separa físicamente.

### D2. Helpers privados centralizados en `SessionService`

**Decisión**: Añadir `sessionRedisKey(string $sid): string` y `sessionRedisConnection(): Connection` como métodos privados en `SessionService`. Los 4 call sites (`sessionExistsInRedis`, `killSession`, `countActiveSessions`, `cleanOrphans`) SHALL usar estos helpers.

**Por qué**: La raíz del bug fue que `sessionExistsInRedis` y `killSession` replicaron la lógica de clave por separado, y cada una eligió una conexión distinta. Centralizar elimina la posibilidad de divergencia.

**Alternativas consideradas**:
- *A. Trait compartido* — sobreingeniería para dos métodos.
- *B. Clase dedicada `SessionRedisRepository`* — válido pero introduce un nuevo archivo sin un motivo fuerte; `SessionService` ya es el hogar natural de esta lógica.

### D3. Guardarraíl de ratio máximo en `cleanOrphans()`

**Decisión**: Si `would_delete / scanned > 0.5`, abortar la corrida sin borrar nada y emitir `sessions.cleanup.aborted_mass_delete`. Umbral configurable vía `system_settings.sessions_cleanup_max_ratio` (default `0.5`).

**Por qué**: El bug que vamos a fixear borró el 100% de filas en cada corrida durante semanas sin que nada alertara. Un guardarraíl de ratio atrapa cualquier futuro bug del mismo tipo antes de que llegue a producción.

**Por qué no más bajo (ej. 0.1)**: Con 13 usuarios y patrón de uso normal, una purga del 20% sí podría ser legítima (ej. muchos usuarios inactivos que limpiaron cookies). 50% deja margen para operación normal pero atrapa el patrón "se borró casi todo".

### D4. Mantener el comportamiento de killSession en "solo borrar BD" como fallback

**Decisión**: Cuando `Redis::connection('session')` falle en `killSession()`, loguear warning pero continuar borrando el registro de `user_sessions` (comportamiento actual).

**Por qué**: El logout del usuario se completa vía `SessionTracker` (que ve la fila borrada en BD y responde 401). La basura en Redis expira vía TTL en 24h. Es preferible loguear y seguir que revertir la operación.

**Riesgo residual**: Sesiones "fantasma" en Redis hasta 24h después de un killSession fallido. Aceptable porque (a) son inertes (no hay cookie que las apunte tras matar BD) y (b) TTL las limpia.

### D5. `cleanOrphans(dryRun: true)` como flag, no comando aparte

**Decisión**: Añadir parámetro opcional al método existente en lugar de crear `cleanOrphansDryRun`.

**Por qué**: Es la misma lógica de detección, solo cambia la acción. Reutilizar el método evita duplicación y mantiene la cobertura de tests focalizada.

### D6. Logs estructurados en formato `sessions.cleanup.*`

**Decisión**: Usar prefijos `sessions.cleanup.completed`, `sessions.cleanup.aborted_mass_delete`, `sessions.cleanup.dry_run`, `sessions.cleanup.interval_too_aggressive` con campos `scanned`, `deleted`, `would_delete`, `ratio`, `duration_ms`, `started_at`, `threshold`.

**Por qué**: Permite filtrar `laravel.log` por prefijo y construir alertas/dashboards con `grep` simple.

## Risks / Trade-offs

- **R1. Cambio de DB lógica (default `2`) requiere migrar claves existentes en Redis** → Mitigación: la migración del task 6 copia claves de DB 0 a DB 2 antes del switch. Después de validar que no hay logout masivo durante 24h, las claves viejas en DB 0 se purgan con `redis-cli -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | xargs -r redis-cli -n 0 del`. **Crítico**: NO borrar las claves de DB 0 antes de la migración, o todos los usuarios quedan deslogueados.
- **R2. Cambiar `config/session.php::connection` puede afectar el override interno de Laravel** → Mitigación: con la conexión `session` siendo explícita, ya no dependemos del override `setConnection('default')`. Verificar tras el cambio que `Session::getId()` sigue funcionando y que la cookie mantiene su TTL de 24h (test de regresión en task 7).
- **R3. Si `sessionExistsInRedis()` queda con un bug, no hay segunda línea de defensa** → Mitigación: el guardarraíl de ratio (D3) actúa como circuit breaker. Adicionalmente, el task 8 deja una métrica observable para detectar anomalías.
- **R4. `countActiveSessions()` con la conexión correcta puede bloquear el login brevemente si Redis está saturado** → Mitigación: el try/catch existente ya cuenta como activa en caso de excepción (comportamiento conservador).
- **R5. Tests de integración contra Redis real requieren fixture de Redis** → Mitigación: usar `phpredis` real contra `127.0.0.1:6379` (mismo Redis que producción) con DB 15 dedicada para tests, limpiada en `setUp`/`tearDown`. No usamos mocks porque el bug fue exactamente por no probar contra Redis real.

## Migration Plan

### Pasos de despliegue (orden estricto)

1. **Pre-deploy**: backup de `config/session.php`, `config/database.php`, `app/app/Services/SessionService.php`. Anotar conteo de claves en Redis DB 0 (`redis-cli -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | wc -l`) para comparar post-deploy.
2. **Deploy código + config**:
   - Merge del PR con los 4 helpers nuevos, `killSession` y `sessionExistsInRedis` corregidos, y `config/database.php` con la conexión `session`.
   - `config/session.php` apuntando a `session`.
   - `routes/console.php` con el cron instrumentado.
3. **Migración de claves en Redis** (sin downtime):
   - `redis-cli -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | xargs -I{} redis-cli -n 2 RENAME "{}" "{}"` (copia a DB 2; verifica que la cookie sigue funcionando).
   - Esperar 5 min, validar que ningún usuario reporta logout.
4. **Purga de claves legacy** (24h después, cuando se confirma estabilidad):
   - `redis-cli -n 0 --scan --pattern 'tcloud_tcloud_cache_*' | xargs -r redis-cli -n 0 DEL`.
5. **Post-deploy monitoring** (48h):
   - `grep "sessions.cleanup" storage/logs/laravel.log` debe mostrar `aborted_mass_delete: 0` y `completed` con ratio bajo.
   - `SELECT COUNT(*) FROM user_sessions;` debe estabilizarse (no caer a 0 cada 30 min como antes).

### Rollback

Si tras el deploy se detecta logout masivo:

1. Revertir el merge (un solo commit recomendado).
2. `redis-cli -n 2 --scan --pattern 'tcloud_tcloud_cache_*' | xargs -I{} redis-cli -n 0 RENAME "{}" "{}"` (mueve de vuelta a DB 0).
3. Re-evaluar antes de re-intentar.

## Open Questions

Ninguna que merezca diferirse: las decisiones D1-D6 están todas validadas contra la evidencia empírica y los specs ya están escritos. El único punto que podría afinarse durante implementación es el valor exacto del umbral de ratio (D3), pero 50% es un default razonable y configurable vía `system_settings` para tuning posterior sin redeploy.

## Lessons learned durante implementación

### L1. `session.store` ≠ `session.connection`

**Bug encontrado en deploy inicial** (2026-09-05, recuperado en <5 min):
- `config/session.php::store` y `config/session.php::connection` tienen propósitos distintos aunque sus nombres sugieran lo contrario.
- `session.store` se pasa a `Cache::store($store)`, que busca un cache store registrado en `config/cache.php::stores`. NO es la conexión Redis.
- `session.connection` se pasa a `Redis::setConnection()` internamente en `SessionManager::createRedisDriver()`, sobreescribiendo la conexión del cache store que se acaba de obtener.
- Setear `session.store = 'session'` hace que Laravel busque un cache store llamado 'session' (que no existe), cayendo a `Illuminate\Cache\SessionStore` que no tiene `setConnection()` → HTTP 500 en todo el sitio.
- **Regla**: `session.store` se deja en null (usa el default 'redis'). `session.connection` es el que apunta a la conexión Redis dedicada (`session`).
