## ADDED Requirements

### Requirement: Conteo de sesiones activas basado en Redis
El sistema SHALL contar como "sesión activa" únicamente los registros `UserSession` que además tienen una clave activa en Redis. Los registros en DB sin clave Redis correspondiente (sesiones huérfanas) NO MUST contarse hacia el límite de sesiones simultáneas.

#### Scenario: Usuario con sesiones huérfanas intenta login
- **WHEN** un usuario tiene 5 registros `UserSession` en DB con `expires_at IS NULL`
- **AND** solo 1 de esos registros tiene una clave activa en Redis
- **THEN** `countActiveSessions()` SHALL retornar 1 (no 5)
- **AND** el sistema SHALL permitir el login si el límite configurado es >= 2

#### Scenario: Usuario con todas las sesiones activas en Redis
- **WHEN** un usuario tiene 3 registros `UserSession` en DB
- **AND** los 3 tienen clave activa en Redis
- **THEN** `countActiveSessions()` SHALL retornar 3

#### Scenario: Redis no disponible durante conteo
- **WHEN** `Redis::exists()` lanza una excepción durante `countActiveSessions()`
- **THEN** el sistema SHALL contar ese registro como activo (comportamiento conservador)
- **AND** el límite de sesiones SHALL seguir aplicándose correctamente

### Requirement: Cleanup automático de sesiones huérfanas y expiradas
El sistema SHALL ejecutar automáticamente cada 30 minutos una tarea programada que elimine de la tabla `user_sessions` los registros que ya no tienen clave en Redis (huérfanos) y los registros con `expires_at` en el pasado (expirados).

#### Scenario: Cleanup elimina sesiones huérfanas
- **WHEN** la tarea programada se ejecuta
- **AND** existen registros `UserSession` cuya clave Redis ya no existe
- **THEN** esos registros SHALL ser eliminados de la tabla `user_sessions`

#### Scenario: Cleanup elimina sesiones expiradas
- **WHEN** la tarea programada se ejecuta
- **AND** existen registros `UserSession` con `expires_at < NOW()`
- **THEN** esos registros SHALL ser eliminados de la tabla `user_sessions`

#### Scenario: Cleanup no afecta sesiones activas
- **WHEN** la tarea programada se ejecuta
- **AND** un registro `UserSession` tiene `expires_at IS NULL` y su clave Redis existe
- **THEN** ese registro SHALL permanecer en la tabla `user_sessions`
