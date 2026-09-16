## MODIFIED Requirements

### Requirement: cleanOrphans busca sesiones con el prefijo correcto del cache store

> **Cambio**: Reemplaza la verificación contra la conexión Redis incorrecta (`cache` / DB 1) por la nueva conexión dedicada `session` introducida en el capability `session-redis-connection`. El helper `SessionService::sessionExistsInRedis()` SHALL usar la conexión `session`, no `cache` ni `default`.

El método `SessionService::cleanOrphans()` SHALL verificar la existencia de sesiones en Redis usando el helper `sessionExistsInRedis()` (que aplica tanto el prefijo de opciones Redis como el prefijo del cache store, y consulta la conexión `session` donde Laravel realmente guarda las sesiones). Esto evita falsos negativos que borran sesiones válidas.

#### Scenario: Sesión activa no se borra por cleanOrphans
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (guardada como `{redis_prefix}{cache_prefix}{session_id}` en la conexión `session`)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: Sesión huérfana real sí se borra
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe en la conexión `session`
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado (salvo que el guardarraíl de ratio máximo aborte la corrida, ver `session-cleanup-safety`)

#### Scenario: Redis cae y el helper lanza excepción
- **WHEN** la conexión `session` lanza una excepción (Redis inalcanzable)
- **THEN** el sistema MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)

### Requirement: Verificación de existencia de sesión en Redis con conexión y prefijo correctos

> **Cambio**: Sustituye la redacción previa que referenciaba `Redis::connection('cache')` (incorrecto: DB 1) por la conexión `session` (donde Laravel persistió la sesión). Centraliza la lógica en `SessionService::sessionExistsInRedis()` para eliminar la contradicción interna con el requirement anterior.

El `SessionService` SHALL verificar la existencia de sesiones en Redis usando exclusivamente la conexión `session` configurada en `config/database.php`. El prefijo aplicado SHALL ser `{database.redis.options.prefix}{cache.prefix}{session_id}` (ej. `tcloud_tcloud_cache_{session_id}`). La construcción de clave y selección de conexión SHALL estar centralizada en los helpers `sessionRedisKey()` y `sessionRedisConnection()` del capability `session-redis-connection`.

#### Scenario: cleanOrphans no borra sesiones activas
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (clave `{redis_prefix}{cache_prefix}{session_id}` existe en la conexión `session`)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: cleanOrphans sí borra sesiones huérfanas
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave ya expiró o no existe en la conexión `session`
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado

#### Scenario: countActiveSessions cuenta sesiones correctamente
- **WHEN** `countActiveSessions()` evalúa las sesiones de un usuario
- **THEN** cada sesión MUST ser verificada con `sessionExistsInRedis()` en la conexión `session`
- **AND** solo las sesiones con clave existente en Redis MUST ser contadas

#### Scenario: killSession elimina la sesión del Redis correcto
- **WHEN** `killSession()` elimina una sesión
- **THEN** la clave `{redis_prefix}{cache_prefix}{session_id}` MUST ser eliminada de la conexión `session`
- **AND** la clave de caché `session_valid:{session_id}` MUST ser eliminada del cache store
- **AND** el registro en `user_sessions` MUST ser eliminado

#### Scenario: Redis cae y la verificación lanza excepción
- **WHEN** la conexión `session` lanza una excepción (Redis inalcanzable)
- **THEN** `cleanOrphans()` MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)
- **AND** `countActiveSessions()` MUST capturar la excepción y contar la sesión como activa (conservador)

## REMOVED Requirements

### Requirement: cleanOrphans busca sesiones con Cache::has (variante anterior)
**Reason**: La redacción previa requería `Cache::has($session->session_id)`, pero `Cache::has` consulta el cache store (DB 1 por default), no la conexión donde Laravel guarda sesiones vía override de `setConnection()`. En la práctica esto daba el mismo falso negativo que `Redis::connection('cache')->exists()`. La nueva conexión dedicada `session` elimina la ambigüedad.
**Migration**: Ninguna desde el punto de vista del caller; el helper `sessionExistsInRedis()` encapsula la nueva lógica. Tests existentes deben actualizar su aserción sobre la conexión usada.

### Requirement: Verificación con Redis::connection('cache') (variante anterior)
**Reason**: La conexión `cache` apunta a la base de datos lógica `1` de Redis, que NO contiene las claves de sesión. Las sesiones viven en una conexión que las override de `SessionManager::createRedisDriver()` redirige a `default` (DB 0). Usar `cache` producía falsos negativos universales: `sessionExistsInRedis()` siempre retornaba `false` y `cleanOrphans` borraba todas las filas.
**Migration**: Reemplazado por el helper `sessionExistsInRedis()` que usa la nueva conexión `session` definida en `config/database.php`. Ver capability `session-redis-connection`.
