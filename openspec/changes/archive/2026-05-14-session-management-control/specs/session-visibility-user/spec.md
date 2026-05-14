## ADDED Requirements

### Requirement: Vista de sesiones propias del usuario
El sistema SHALL proveer al usuario autenticado la ruta `GET /user/sessions` (protegida por middleware `auth`) que devuelve JSON con sus sesiones activas de `user_sessions`. La vista SHALL estar integrada como sección en el dashboard del usuario (`resources/views/dashboard/user.blade.php`).

#### Scenario: Usuario ve sus sesiones activas
- **WHEN** el usuario accede a su dashboard y expande la sección "Mis sesiones"
- **THEN** ve una tabla con: IP, Dispositivo (user_agent resumido), Inicio, Última actividad, indicador "Esta sesión" para la sesión actual, botón Cerrar para las demás

#### Scenario: Solo ve sus propias sesiones
- **WHEN** el usuario accede a `/user/sessions`
- **THEN** solo recibe sesiones donde `user_id` coincide con la sesión actual
- **THEN** no puede acceder a sesiones de otros usuarios

### Requirement: Indicador de sesión actual
La sesión cuyo `session_id` coincide con `Session::getId()` SHALL estar marcada visualmente como "Esta sesión" y su botón "Cerrar" SHALL estar deshabilitado o ausente.

#### Scenario: Sesión actual marcada
- **WHEN** el usuario ve la lista de sus sesiones
- **THEN** la fila correspondiente a su sesión activa muestra el badge "Esta sesión" y no tiene botón de cierre

### Requirement: Cierre de sesión propia individual
El sistema SHALL proveer `DELETE /user/sessions/{id}` que permite al usuario cerrar una de sus propias sesiones (no la actual). El sistema SHALL verificar que el `user_id` del registro coincide con el usuario en sesión antes de proceder.

#### Scenario: Usuario cierra una sesión remota propia
- **WHEN** el usuario hace clic en "Cerrar" en una de sus sesiones (no la actual) y confirma
- **THEN** la key de Redis de esa sesión es eliminada
- **THEN** el registro de `user_sessions` es eliminado
- **THEN** la lista se actualiza y ya no muestra esa sesión

#### Scenario: Usuario no puede cerrar la sesión actual
- **WHEN** el usuario intenta hacer `DELETE /user/sessions/{id}` donde el id corresponde a su sesión activa
- **THEN** el sistema retorna 403 con mensaje "No puedes cerrar tu sesión actual desde aquí. Usa Cerrar Sesión."

#### Scenario: Usuario no puede cerrar sesión de otro usuario
- **WHEN** el usuario intenta hacer `DELETE /user/sessions/{id}` donde el id pertenece a otro usuario
- **THEN** el sistema retorna 403 Forbidden

### Requirement: Cierre masivo de otras sesiones propias
El sistema SHALL proveer `DELETE /user/sessions` (sin ID) que cierra todas las sesiones del usuario excepto la sesión activa actual.

#### Scenario: Cerrar todas las otras sesiones
- **WHEN** el usuario hace clic en "Cerrar todas las otras sesiones"
- **THEN** todas las sesiones en `user_sessions` de ese usuario, excepto la actual, son invalidadas en Redis y eliminadas de BD
- **THEN** la lista queda con solo la sesión actual visible
