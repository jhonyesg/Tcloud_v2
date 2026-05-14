## ADDED Requirements

### Requirement: Límite global de sesiones simultáneas
El sistema SHALL mantener en `system_settings` el valor `global_max_sessions` (entero >= 0) que define el número máximo de sesiones activas por usuario por defecto. El valor inicial SHALL ser 6. El valor 0 significa sin límite.

#### Scenario: Valor global activo
- **WHEN** un usuario no tiene `max_sessions` propio configurado
- **THEN** el sistema usa `global_max_sessions` de `system_settings` como su límite efectivo

### Requirement: Límite de sesiones por usuario individual
El sistema SHALL permitir al admin configurar `max_sessions` en cada usuario. Si el valor es 0, ese usuario no tiene límite. Si es NULL, hereda el global.

#### Scenario: Override por usuario
- **WHEN** el admin configura `max_sessions = 2` para un usuario
- **THEN** ese usuario solo puede tener 2 sesiones activas simultáneas, independientemente del global

#### Scenario: Sin límite por usuario
- **WHEN** el admin configura `max_sessions = 0` para un usuario
- **THEN** ese usuario puede iniciar sesión sin restricción de concurrencia

### Requirement: Bloqueo de login al superar el límite
El sistema SHALL rechazar el intento de login cuando el número de sesiones activas del usuario (registros en `user_sessions` con `expires_at IS NULL OR expires_at > now()`) sea mayor o igual a su límite efectivo.

#### Scenario: Login bloqueado por límite
- **WHEN** un usuario tiene 6 sesiones activas y su límite efectivo es 6
- **THEN** el login falla y se muestra el mensaje: "Límite de sesiones simultáneas superado. Cierra una sesión desde otro dispositivo e intenta de nuevo."
- **THEN** no se crea ninguna sesión nueva en Redis ni en `user_sessions`

#### Scenario: Login permitido bajo el límite
- **WHEN** un usuario tiene 4 sesiones activas y su límite efectivo es 6
- **THEN** el login procede normalmente y se registra la nueva sesión en `user_sessions`

### Requirement: Registro de sesión al hacer login
Al completar un login exitoso, el sistema SHALL insertar un registro en `user_sessions` con: `user_id`, `session_id` (= `Session::getId()`), `ip_address`, `user_agent`, `created_at`, `last_activity_at` y `expires_at`.

#### Scenario: Registro creado en login
- **WHEN** un usuario hace login exitosamente
- **THEN** existe un registro en `user_sessions` con su `user_id` y el `session_id` del cookie `tcloud_session`

### Requirement: Eliminación de registro al hacer logout
Al hacer logout, el sistema SHALL eliminar de `user_sessions` el registro correspondiente al `session_id` de la sesión que se cierra, además de hacer `Session::flush()`.

#### Scenario: Registro eliminado en logout
- **WHEN** un usuario hace logout desde `GET /logout`
- **THEN** el registro en `user_sessions` con ese `session_id` es eliminado
- **THEN** la sesión en Redis es invalidada
