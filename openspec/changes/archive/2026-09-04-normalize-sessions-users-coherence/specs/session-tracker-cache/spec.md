## MODIFIED Requirements

### Requirement: Verificación de existencia de sesión en Redis con conexión y prefijo correctos
El `SessionService` SHALL verificar la existencia de sesiones en Redis usando `Redis::connection('cache')` con el prefijo del cache store (`config('cache.prefix')`), produciendo la clave `{redis_prefix}{cache_prefix}{session_id}` (ej. `tcloud_tcloud_cache_{session_id}`). Esto se centraliza en un helper `sessionExistsInRedis()` para evitar discrepancias entre el cache store (DB 1) y la conexión de sesión (DB 0).

#### Scenario: cleanOrphans no borra sesiones activas
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (clave `tcloud_tcloud_cache_{session_id}` existe en DB 1, conexión `cache`)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: cleanOrphans sí borra sesiones huérfanas
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe en DB 1
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado

#### Scenario: countActiveSessions cuenta sesiones correctamente
- **WHEN** `countActiveSessions()` evalúa las sesiones de un usuario
- **THEN** cada sesión MUST ser verificada con `sessionExistsInRedis()` en DB 1 (conexión `cache`)
- **AND** solo las sesiones con clave existente en Redis MUST ser contadas

#### Scenario: killSession elimina la sesión del Redis correcto
- **WHEN** `killSession()` elimina una sesión
- **THEN** la clave `tcloud_tcloud_cache_{session_id}` MUST ser eliminada de DB 1 (conexión `cache`)
- **AND** la clave de caché `session_valid:{session_id}` MUST ser eliminada del cache store

#### Scenario: Redis cae y la verificación lanza excepción
- **WHEN** `Redis::connection('cache')` lanza una excepción (Redis inalcanzable)
- **THEN** `cleanOrphans()` MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)
- **AND** `countActiveSessions()` MUST capturar la excepción y contar la sesión como activa (conservador)

## ADDED Requirements

### Requirement: Verificación de users.status en SessionTracker

El middleware `SessionTracker` SHALL, tras confirmar que la fila en `user_sessions` existe y no está expirada, verificar el `status` del `User` asociado a esa sesión. Si el `status` no es `active`, el middleware MUST eliminar la sesión (fila DB + claves Redis) y responder `401` con header `X-Session-Expired: 1`, exactamente igual que cuando la sesión no existe o está expirada.

#### Scenario: Sesión válida pero usuario con status distinto de active
- **WHEN** llega un request autenticado cuya fila en `user_sessions` existe, no está expirada, pero el `User` asociado tiene `status` distinto de `active`
- **THEN** el middleware MUST llamar a `SessionService::killSession()` sobre esa fila
- **AND** el middleware MUST responder `401` con header `X-Session-Expired: 1` (JSON) o redirigir a `/login` (HTML) con el mensaje "Tu sesión fue cerrada. Inicia sesión nuevamente."

#### Scenario: Sesión válida y usuario con status active
- **WHEN** llega un request autenticado cuya fila en `user_sessions` existe, no está expirada, y el `User` asociado tiene `status = 'active'`
- **THEN** el middleware MUST continuar con el flujo normal (renovación de `expires_at` y paso al siguiente handler)

#### Scenario: Reutilización del cache de session_valid existente
- **WHEN** llega un request y la clave `session_valid:{session_id}` ya está en cache
- **THEN** el middleware MUST NO re-verificar el `status` del usuario hasta que el cache expire (30 s)
- **AND** el cambio de `status` se reflejará como máximo en el siguiente request posterior al vencimiento
