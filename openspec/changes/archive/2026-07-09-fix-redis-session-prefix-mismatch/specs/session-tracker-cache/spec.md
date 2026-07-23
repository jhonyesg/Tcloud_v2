## ADDED Requirements

### Requirement: Verificación de existencia de sesión en Redis con conexión y prefijo correctos
El `SessionService` SHALL verificar la existencia de sesiones en Redis usando `Redis::connection('default')` con el prefijo del cache store (`config('cache.prefix')`), produciendo la clave `{redis_prefix}{cache_prefix}{session_id}` (ej. `tcloud_tcloud_cache_{session_id}`). Esto se centraliza en un helper `sessionExistsInRedis()` para evitar discrepancias entre el cache store (DB 1) y la conexión de sesión (DB 0).

#### Scenario: cleanOrphans no borra sesiones activas
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (clave `tcloud_tcloud_cache_{session_id}` existe en DB 0)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: cleanOrphans sí borra sesiones huérfanas
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe en DB 0
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado

#### Scenario: countActiveSessions cuenta sesiones correctamente
- **WHEN** `countActiveSessions()` evalúa las sesiones de un usuario
- **THEN** cada sesión MUST ser verificada con `sessionExistsInRedis()` en DB 0
- **AND** solo las sesiones con clave existente en Redis MUST ser contadas

#### Scenario: killSession elimina la sesión del Redis correcto
- **WHEN** `killSession()` elimina una sesión
- **THEN** la clave `tcloud_tcloud_cache_{session_id}` MUST ser eliminada de DB 0 (conexión `default`)
- **AND** la clave de caché `session_valid:{session_id}` MUST ser eliminada del cache store

#### Scenario: Redis cae y la verificación lanza excepción
- **WHEN** `Redis::connection('default')` lanza una excepción (Redis inalcanzable)
- **THEN** `cleanOrphans()` MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)
- **AND** `countActiveSessions()` MUST capturar la excepción y contar la sesión como activa (conservador)