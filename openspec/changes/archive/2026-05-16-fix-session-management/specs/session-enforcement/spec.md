## ADDED Requirements

### Requirement: DB como fuente de verdad de sesión
El sistema SHALL tratar la tabla `user_sessions` como fuente de verdad. Si un usuario tiene `user_id` en su sesión de Laravel pero no existe ningún registro `UserSession` con su `session_id`, el sistema MUST invalidar la sesión y redirigir al login.

#### Scenario: Request autenticada sin registro DB
- **WHEN** llega una request con un cookie de sesión que contiene `user_id`
- **AND** no existe ningún `UserSession` con ese `session_id` en la base de datos
- **THEN** el sistema SHALL llamar `Session::flush()` y redirigir a `/login`

#### Scenario: Request no autenticada sin registro DB
- **WHEN** llega una request sin `user_id` en la sesión de Laravel
- **AND** no existe ningún `UserSession` con ese `session_id`
- **THEN** el sistema SHALL permitir que la request continúe normalmente (ruta pública o middleware de auth manejará)

#### Scenario: Admin mata todas las sesiones de un usuario
- **WHEN** un admin elimina todos los registros `UserSession` de un usuario desde el panel de administración
- **THEN** en la próxima request HTTP de ese usuario, el sistema SHALL invalidar su sesión y redirigir a `/login`

#### Scenario: Sesión expirada permanece en Redis
- **WHEN** `killSession()` falla al eliminar la clave de Redis (excepción silenciada)
- **AND** el registro DB ya fue eliminado
- **THEN** en la próxima request del usuario, `SessionTracker` SHALL detectar la ausencia del registro DB, hacer flush y redirigir a `/login`

### Requirement: Logging de fallos en eliminación de clave Redis
El sistema SHALL registrar en el log de Laravel cualquier excepción producida al intentar eliminar una clave de sesión de Redis, en lugar de ignorarla silenciosamente.

#### Scenario: Redis::del lanza excepción
- **WHEN** `SessionService::killSession()` llama `Redis::del()` y Redis lanza una excepción
- **THEN** el sistema SHALL registrar la excepción usando `Log::warning()` con el `session_id` afectado
- **AND** continuará eliminando el registro DB
