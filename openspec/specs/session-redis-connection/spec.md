## Purpose

Define el contrato del canal Redis que Laravel usa para persistir sesiones y de los helpers de `SessionService` que consultan y eliminan esas claves. Centralizar la lógica de prefijo y conexión en un único lugar para que ningún caller (cron, controller, middleware) consulte la base de datos incorrecta.

## Requirements

### Requirement: Conexión Redis dedicada para sesiones

El sistema SHALL configurar una conexión Redis con nombre `session` en `config/database.php` que apunte a una base de datos lógica distinta de la conexión `cache`. `config/session.php` SHALL usar `session` como `connection`, de modo que `SessionManager::createRedisDriver()` redirija automáticamente las sesiones a esa conexión sin necesidad de un cache store dedicado.

#### Scenario: Configuración declarativa de la conexión session
- **WHEN** el sistema arranca y lee `config/database.php`
- **THEN** SHALL existir una entrada `redis.session` con `database` configurable vía env (`REDIS_SESSION_DB`, default `2`)
- **AND** `config('session.connection')` SHALL ser `session`
- **AND** `config('session.store')` SHALL dejarse en `null` (Laravel usa el cache store `'redis'` por default; el override de `createRedisDriver()` redirige la conexión a `session`)

#### Scenario: Clave de sesión queda bajo el prefijo de la conexión session
- **WHEN** Laravel guarda una sesión
- **THEN** la clave física en Redis SHALL tener la forma `{redis_prefix}{cache_prefix}{session_id}` dentro de la base de datos lógica de `redis.session`
- **AND** SHALL existir exactamente una única ubicación en Redis donde viven las claves de sesión, verificable con `redis-cli -n <REDIS_SESSION_DB> KEYS "tcloud_tcloud_cache_*"`

### Requirement: Helper único para consultar y eliminar sesiones en Redis

`SessionService` SHALL centralizar la lógica de construcción de clave y selección de conexión en helpers privados: `sessionRedisKey(string $sessionId): string` y `sessionRedisConnection(): \Illuminate\Redis\Connections\Connection`. Tanto `sessionExistsInRedis()` como `killSession()` SHALL usar estos helpers, de modo que un cambio futuro en la conexión o el prefijo se propague sin posibilidad de divergencia.

#### Scenario: Sesión activa se detecta correctamente
- **WHEN** `sessionExistsInRedis($sid)` se invoca con un `session_id` cuya clave existe en la conexión `session`
- **THEN** SHALL retornar `true`
- **AND** SHALL usar exclusivamente `Redis::connection('session')` (no `cache` ni `default`)

#### Scenario: Sesión expirada se detecta como inexistente
- **WHEN** `sessionExistsInRedis($sid)` se invoca con un `session_id` cuya clave ya fue evictada o nunca existió
- **THEN** SHALL retornar `false`

#### Scenario: killSession elimina la clave correcta
- **WHEN** `killSession($record)` corre
- **THEN** SHALL borrar `sessionRedisKey($record->session_id)` en la conexión `session`
- **AND** SHALL borrar `session_valid:{session_id}` del cache store (DB 1)
- **AND** SHALL eliminar el registro de `user_sessions`

#### Scenario: Excepción de Redis no rompe la operación
- **WHEN** la conexión `session` lanza una excepción durante `sessionExistsInRedis()` o `killSession()`
- **THEN** `cleanOrphans()` SHALL capturar la excepción y NO borrar el registro (conservador)
- **AND** `countActiveSessions()` SHALL capturar la excepción y contar la sesión como activa (conservador)
- **AND** `killSession()` SHALL loguear el warning y continuar eliminando el registro de `user_sessions`

### Requirement: Coherencia entre SessionTracker y el helper

El middleware `SessionTracker` SHALL confiar en el resultado de `SessionService::sessionExistsInRedis()` para todas las decisiones que dependan de la presencia real de la sesión en Redis. Ningún path del middleware SHALL consultar Redis directamente para validar la existencia de la sesión.

#### Scenario: SessionTracker delega en SessionService
- **WHEN** `SessionTracker` necesita saber si la sesión está en Redis (vía cleanOrphans o cualquier verificación futura)
- **THEN** SHALL invocar `SessionService::sessionExistsInRedis($sid)`
- **AND** SHALL NO usar `Redis::connection('cache')` ni `Redis::connection('default')` directamente
