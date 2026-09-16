## Purpose

Define el contrato que mantiene coherentes la tabla `users` y la tabla `user_sessions`: cuando la cuenta de un usuario pasa por cambios de ciclo de vida (transiciones de `status` o eliminación del usuario), todas las sesiones activas de ese usuario deben eliminarse tanto de PostgreSQL como de Redis (conexión `session`), y el frontend debe tratar esa eliminación como una señal de sesión expirada.

## Requirements

### Requirement: Eviction de sesiones cuando el usuario deja de estar activo

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

### Requirement: Validación de status en cada request autenticado

El middleware `SessionTracker` SHALL verificar que el `status` del usuario asociado a la sesión actual sea `active` antes de permitir el paso del request. Si no lo es, la sesión MUST ser eliminada (DB + Redis en conexión `session`) y el usuario MUST recibir `401` con header `X-Session-Expired: 1`.

#### Scenario: Sesión válida pero usuario deshabilitado
- **WHEN** llega un request autenticado cuya fila en `user_sessions` existe y no está expirada
- **AND** el `User` asociado tiene `status = 'disabled'`
- **THEN** el middleware MUST eliminar la fila de `user_sessions` y borrar las claves Redis asociadas (conexión `session`)
- **AND** el middleware MUST responder `401` con header `X-Session-Expired: 1`

#### Scenario: Sesión válida y usuario activo
- **WHEN** llega un request autenticado cuya fila en `user_sessions` existe y no está expirada
- **AND** el `User` asociado tiene `status = 'active'`
- **THEN** el middleware MUST permitir el paso del request normalmente

#### Scenario: Cache de status válido por 30 segundos
- **WHEN** el middleware verifica `users.status` y encuentra `active`
- **THEN** el resultado MUST cachearse por 30 s bajo la misma clave `session_valid:{session_id}` que ya usa el middleware
- **AND** un cambio de `status` que ocurra dentro de esos 30 s se reflejará como máximo en el siguiente request posterior al vencimiento de la caché

### Requirement: Limpieza de Redis al eliminar un usuario

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
