## Why

El módulo de sesiones tiene dos bugs críticos: (1) las sesiones se acumulan en DB porque el conteo no distingue sesiones reales (con clave en Redis) de registros huérfanos, bloqueando nuevos logins prematuramente; (2) `SessionTracker` no cierra la sesión activa del usuario cuando su registro DB es eliminado, permitiendo que el usuario permanezca autenticado después de que un admin mate todas sus sesiones.

## What Changes

- **`SessionService::countActiveSessions()`**: cambiar el conteo para verificar existencia en Redis, de modo que solo cuentan sesiones con una clave activa en Redis (usuario realmente conectado).
- **`SessionTracker` middleware**: si hay `user_id` en la sesión de Laravel pero no existe registro en DB → flush + redirect a `/login`. Esto garantiza que matar una sesión desde el admin efectivamente expulsa al usuario.
- **`SessionService::killSession()`**: limpiar correctamente la clave de Redis con el prefijo adecuado.
- **Scheduled cleanup**: agregar tarea programada en `routes/console.php` que ejecute `cleanOrphans()` + `cleanExpired()` cada 30 minutos para mantener la tabla limpia.

## Capabilities

### New Capabilities

- `session-enforcement`: Mecanismo que garantiza que el estado de sesión en DB es la fuente de verdad — si no hay registro en DB, el usuario es expulsado aunque tenga cookie válida.
- `active-session-counting`: Conteo de sesiones basado en claves Redis activas, no en registros DB, para aplicar el límite de sesiones simultáneas sobre conexiones reales.

### Modified Capabilities

*(ninguna spec existente cambia sus requisitos funcionales)*

## Impact

- **Modelos**: `UserSession` (sin cambios estructurales)
- **Servicios**: `SessionService` — métodos `countActiveSessions()` y `killSession()`
- **Middleware**: `SessionTracker` — lógica cuando `!$record`
- **Rutas**: `routes/console.php` — nueva tarea scheduled
- **Migraciones**: no requiere
- **No-goals**: no se cambia la UI del panel de sesiones, no se añade WebSocket/push para logout en tiempo real, no se modifica el sistema de permisos de roles
