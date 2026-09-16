## MODIFIED Requirements

### Requirement: Eviction de sesiones cuando el usuario deja de estar activo

> **Cambio**: Aclara que la "clave Redis de la sesión" referenciada vive en la nueva conexión `session` (DB configurable vía `REDIS_SESSION_DB`), no en `cache` (DB 1). Esto es coherente con el capability `session-redis-connection`.

Cuando el `status` de un usuario cambia de `active` a `pending` o `disabled` (o a cualquier valor distinto de `active`), el sistema SHALL invalidar todas las sesiones activas de ese usuario en `user_sessions` y en Redis (conexión `session`, clave `{redis_prefix}{cache_prefix}{session_id}`), además de borrar `session_valid:{session_id}` del cache store. El próximo request autenticado SHALL recibir `401` con header `X-Session-Expired: 1` y ser redirigido a `/login`.

#### Scenario: Admin deshabilita un usuario con sesión abierta
- **WHEN** el administrador envía `PATCH /admin/users/{id}` con `{"status": "disabled"}` y ese usuario tiene al menos una fila en `user_sessions` con `expires_at > now()` o `expires_at IS NULL`
- **THEN** el sistema MUST eliminar esas filas de `user_sessions` y borrar las claves correspondientes en la conexión Redis `session` (`{redis_prefix}{cache_prefix}{session_id}`) y en el cache store (`session_valid:{session_id}`)
- **AND** el siguiente request autenticado de ese usuario MUST responder `401` con header `X-Session-Expired: 1`

#### Scenario: Admin regresa un usuario a active
- **WHEN** el administrador envía `PATCH /admin/users/{id}` con `{"status": "active"}`
- **THEN** el sistema MUST NO tocar las sesiones existentes del usuario (solo aplica a transiciones hacia no-`active`)

#### Scenario: Transición a active vía setup-password
- **WHEN** un usuario pendiente consume su token de `setup-password` (`PasswordTokenService::consume` con `TYPE_SETUP`)
- **THEN** el sistema MUST cambiar su `status` a `active`
- **AND** como el usuario aún no estaba autenticado, no hay sesiones que evictar

### Requirement: Limpieza de Redis al eliminar un usuario

> **Cambio**: Mismo cambio de dirección: la clave de sesión está en la conexión `session`, no en `cache`.

Cuando un usuario es eliminado (`User::delete()`), el sistema MUST limpiar todas las claves Redis asociadas a las sesiones de ese usuario en la conexión `session` (`{redis_prefix}{cache_prefix}{session_id}`) y en el cache store (`session_valid:{session_id}`) ANTES de que el cascade de la base de datos elimine las filas de `user_sessions`.

#### Scenario: Admin elimina un usuario con sesiones activas
- **WHEN** el administrador envía `DELETE /admin/users/{id}` y ese usuario tiene filas en `user_sessions`
- **THEN** el sistema MUST borrar las claves Redis de cada `session_id` del usuario en la conexión `session` antes de que el cascade las elimine de PostgreSQL
- **AND** el sistema MUST retornar `200 OK` con `{"message": "User deleted"}` una vez completado

#### Scenario: Admin elimina un usuario sin sesiones
- **WHEN** el administrador envía `DELETE /admin/users/{id}` y ese usuario no tiene filas en `user_sessions`
- **THEN** el sistema MUST completar la eliminación sin tocar Redis
- **AND** el sistema MUST retornar `200 OK` con `{"message": "User deleted"}`

#### Scenario: Redis falla durante la limpieza de delete
- **WHEN** durante `User::delete()` el intento de borrar una clave Redis lanza una excepción
- **THEN** el sistema MUST registrar el error en el log y continuar con la eliminación del usuario (la fila DB se elimina vía cascade de todas formas)
- **AND** el sistema MUST NO revertir la eliminación del usuario
