## ADDED Requirements

### Requirement: Vista global de sesiones activas
El sistema SHALL proveer al admin la ruta `GET /admin/sessions` (protegida por middleware `['auth', 'admin']`) que muestra todas las sesiones activas registradas en `user_sessions`, ordenadas por `last_activity_at` descendente.

#### Scenario: Admin accede a la lista de sesiones
- **WHEN** el admin navega a `/admin/sessions`
- **THEN** ve una tabla con columnas: Usuario (email), IP, Dispositivo (user_agent resumido), Inicio de sesión, Última actividad, Expira en, Acciones

#### Scenario: No-admin bloqueado
- **WHEN** un usuario no-admin intenta acceder a `/admin/sessions`
- **THEN** recibe 403 Forbidden

### Requirement: Filtro de sesiones por usuario
La vista `/admin/sessions` SHALL permitir filtrar la tabla ingresando email o username del usuario en un campo de búsqueda client-side (Alpine.js).

#### Scenario: Filtrar por email
- **WHEN** el admin escribe parte del email en el campo de búsqueda
- **THEN** la tabla muestra solo las sesiones cuyo usuario contiene ese texto

### Requirement: Cierre remoto de sesión individual (admin)
El sistema SHALL proveer `DELETE /admin/sessions/{id}` que invalida una sesión específica: elimina la key de Redis y el registro de `user_sessions`. Solo accesible por admin.

#### Scenario: Admin cierra sesión de un usuario
- **WHEN** el admin hace clic en "Cerrar" junto a una sesión y confirma
- **THEN** la key de Redis correspondiente es eliminada
- **THEN** el registro en `user_sessions` es eliminado
- **THEN** la tabla se recarga y ya no muestra esa sesión
- **THEN** el usuario afectado es deslogueado en su próximo request

### Requirement: Cierre masivo de sesiones por usuario (admin)
El sistema SHALL proveer `DELETE /admin/sessions/user/{userId}` que invalida todas las sesiones de un usuario específico.

#### Scenario: Admin cierra todas las sesiones de un usuario
- **WHEN** el admin selecciona un usuario y hace clic en "Cerrar todas las sesiones"
- **THEN** todas las keys de Redis de ese usuario son eliminadas
- **THEN** todos los registros de `user_sessions` de ese usuario son eliminados

### Requirement: Configuración de límites en modal de usuario existente
El sistema SHALL extender el modal de edición de usuario en `GET /admin/users` con los campos `max_sessions` (número entero, 0 = sin límite) y `session_lifetime_minutes` (número entero, 0 = sin expiración, vacío = heredar global).

#### Scenario: Admin edita límites de usuario desde modal
- **WHEN** el admin abre el modal de edición de un usuario y modifica `max_sessions` a 3
- **THEN** al guardar, el campo `max_sessions` del usuario se actualiza a 3 en BD
- **THEN** el nuevo límite se aplica en el próximo intento de login de ese usuario

### Requirement: Configuración global de sesiones
El sistema SHALL proveer en el panel admin (dentro de `/admin/sessions`) un formulario para editar `global_max_sessions` y `global_session_lifetime` de `system_settings`.

#### Scenario: Admin cambia el límite global
- **WHEN** el admin actualiza `global_max_sessions = 3` y guarda
- **THEN** el valor en `system_settings` se actualiza
- **THEN** usuarios sin override propio ahora tienen límite de 3 sesiones
