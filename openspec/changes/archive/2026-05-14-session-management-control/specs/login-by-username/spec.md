## MODIFIED Requirements

### Requirement: Login registra sesión activa y verifica límite
El flujo de login SHALL verificar el límite de sesiones simultáneas del usuario antes de crear la sesión, y SHALL registrar la sesión en `user_sessions` al completarse exitosamente.

Antes del login exitoso:
1. Determinar el límite efectivo: `user.max_sessions` si no es NULL, sino `system_settings.global_max_sessions`. Si el límite efectivo es 0, no hay restricción.
2. Contar sesiones activas: `SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND (expires_at IS NULL OR expires_at > now())`.
3. Si `count >= límite_efectivo > 0`: rechazar con error `"Límite de sesiones simultáneas superado. Cierra una sesión desde otro dispositivo e intenta de nuevo."`.
4. Si OK: `Session::regenerate()`, `Session::put(...)`, INSERT en `user_sessions`.

#### Scenario: Login bloqueado al alcanzar límite
- **WHEN** el usuario tiene tantas sesiones activas como su límite efectivo
- **THEN** el login devuelve `back()->with('error', 'Límite de sesiones simultáneas superado...')`
- **THEN** no se crea sesión en Redis ni registro en `user_sessions`

#### Scenario: Login exitoso registra sesión
- **WHEN** el usuario hace login correctamente y está bajo el límite
- **THEN** se crea un registro en `user_sessions` con todos los campos requeridos
- **THEN** `expires_at` se calcula según `session_lifetime_minutes` del usuario o el global

#### Scenario: Login con usuario sin límite configurado
- **WHEN** el usuario tiene `max_sessions = 0`
- **THEN** el login procede sin verificar número de sesiones activas
