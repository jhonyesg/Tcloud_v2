## ADDED Requirements

### Requirement: Tiempo de sesión global configurable
El sistema SHALL mantener en `system_settings` el valor `global_session_lifetime` (entero en minutos, >= 0). El valor 0 significa sin expiración. El valor inicial SHALL ser 120 (2 horas). Este valor reemplaza al `SESSION_LIFETIME` de `.env` como fuente de verdad para la lógica de negocio (`.env` puede quedar como fallback de arranque).

#### Scenario: Global activo para usuario sin override
- **WHEN** un usuario no tiene `session_lifetime_minutes` propio configurado
- **THEN** su `expires_at` al login es `now() + global_session_lifetime minutes`

#### Scenario: Sin expiración global
- **WHEN** `global_session_lifetime = 0`
- **THEN** todas las sesiones de usuarios sin override tienen `expires_at = NULL`

### Requirement: Tiempo de sesión por usuario individual
El sistema SHALL permitir al admin configurar `session_lifetime_minutes` en cada usuario (INT NULL). NULL significa heredar el global. 0 significa sin expiración para ese usuario.

#### Scenario: Override de tiempo por usuario
- **WHEN** el admin configura `session_lifetime_minutes = 1440` (24h) para un usuario
- **THEN** ese usuario tiene `expires_at = now() + 1440 min` en cada login nuevo

#### Scenario: Sin expiración por usuario
- **WHEN** el admin configura `session_lifetime_minutes = 0` para un usuario
- **THEN** ese usuario tiene `expires_at = NULL` (sesión no expira por tiempo)

### Requirement: Validación de expiración en cada request
El middleware `SessionTracker` SHALL verificar en cada request autenticado si `expires_at` del registro actual en `user_sessions` ha pasado. Si expiró: invalida la sesión de Redis, elimina el registro de `user_sessions` y redirige a `/login` con mensaje de expiración.

#### Scenario: Sesión expirada interceptada
- **WHEN** el usuario hace un request y su `expires_at` en `user_sessions` es anterior a `now()`
- **THEN** se elimina el registro de `user_sessions`
- **THEN** se hace `Session::flush()` para limpiar Redis
- **THEN** el usuario es redirigido a `/login` con mensaje "Tu sesión ha expirado. Inicia sesión nuevamente."

#### Scenario: Sesión sin expiración nunca se invalida por tiempo
- **WHEN** el usuario tiene `expires_at = NULL` en su registro de `user_sessions`
- **THEN** el middleware no invalida la sesión por tiempo

### Requirement: Actualización de actividad con throttle
El middleware `SessionTracker` SHALL actualizar `last_activity_at` en `user_sessions` máximo una vez por minuto por sesión, para no generar escrituras excesivas en BD.

#### Scenario: Throttle de actividad
- **WHEN** el usuario hace múltiples requests en menos de 60 segundos
- **THEN** `last_activity_at` se actualiza solo en el primero y no en los subsiguientes dentro del mismo minuto
