## Purpose

Garantiza que `SessionService` apunte a la misma clave Redis que el handler de sesión de Laravel al consultar/borrar sesiones, evitando las sesiones huérfanas de Redis que se quedan vivas después de `killSession()` y rompen el contrato operacional de cierre de sesión.

## ADDED Requirements

### Requirement: SessionService compone la clave Redis con un solo prefijo

`SessionService::sessionRedisKey()` SHALL devolver una clave que, tras aplicar el `OPT_PREFIX` automático de phpredis, coincida exactamente con la clave donde Laravel persiste la sesión vía `Cache::store('redis')` o el handler de sesión por defecto.

#### Scenario: sessionExistsInRedis reporta true para sesión viva

- **WHEN** el usuario inicia sesión, se persiste su sesión en Redis y se llama `SessionService::sessionExistsInRedis($sid)`
- **THEN** SHALL devolver `true`
- **AND** la clave construida por `sessionRedisKey()` debe coincidir (bajo phpredis) con la clave donde la sesión está realmente almacenada

#### Scenario: killSession borra la fila de BD y la clave de Redis

- **WHEN** se invoca `SessionService::killSession($userSession)`
- **THEN** SHALL eliminar la fila de `user_sessions`
- **AND** SHALL eliminar la clave de Redis equivalente a la sesión
- **AND** SHALL dejar `DBSIZE` de la DB `session` (configurada por `REDIS_SESSION_DB`) decrementado en uno

#### Scenario: killAllUserSessions limpia todas las sesiones de un usuario

- **WHEN** se invoca `SessionService::killAllUserSessions($user)` con un usuario que tiene N sesiones activas (N ≥ 1)
- **THEN** SHALL eliminar las N filas de `user_sessions` correspondientes
- **AND** SHALL eliminar las N claves de Redis correspondientes
- **AND** SHALL retornar `N`

### Requirement: Composición de claves Redis centralizada en un helper

Toda mutación de claves de sesión en `SessionService` SHALL pasar por el helper privado `sessionRedisKey()`, sin construir el prefijo manualmente en cada método. La regla SHALL ser: **phpredis aplica `OPT_PREFIX` (definido en `database.redis.options.prefix`), `sessionRedisKey()` aporta solo `cache.prefix` + `session_id`**.

#### Scenario: Ningún método construye prefijos fuera del helper

- **WHEN** se inspecciona `SessionService` por métodos que llamen `Redis::connection('session')->{exists,del}(...)` con la clave concatenada a mano
- **THEN** SHALL encontrarse únicamente a través de `sessionRedisKey()`
