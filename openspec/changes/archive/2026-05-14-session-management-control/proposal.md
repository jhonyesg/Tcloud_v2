## Why

El sistema actual no tiene control sobre cuántas sesiones simultáneas puede tener un usuario ni sobre la duración individual de cada sesión (hay un valor global fijo en `.env`). Esto impide limitar accesos concurrentes con una misma cuenta y no ofrece visibilidad ni control a admins ni usuarios sobre las sesiones activas.

## What Changes

- **Nueva tabla** `user_sessions`: registra cada sesión activa (session_id, IP, user agent, timestamps).
- **Nueva tabla** `system_settings`: configuración global clave-valor (`global_max_sessions`, `global_session_lifetime`).
- **Nuevas columnas en `users`**: `max_sessions INT DEFAULT 6`, `session_lifetime_minutes INT NULL`.
- **Nuevo middleware** `SessionTracker`: actualiza actividad y valida expiración en cada request autenticado.
- **`AuthController::login()`**: verifica límite de sesiones antes de crear una nueva; bloquea con mensaje si se supera.
- **Panel admin `/admin/sessions`**: vista global de sesiones activas con cierre remoto.
- **Panel admin `/admin/redis`**: monitor de estado de Redis (conexión, memoria, sesiones, uptime).
- **Modal edición de usuario** (`/admin/users`): nuevos campos `max_sessions` y `session_lifetime_minutes`.
- **Vista usuario "Mis sesiones"**: desde el dashboard, el usuario ve y cierra sus propias sesiones.

## Non-goals

- No se implementa cierre de sesiones desde la pantalla de login (por seguridad).
- No se permite al usuario cambiar su propio límite de sesiones ni su tiempo de expiración.
- No se implementa autenticación multi-factor ni notificaciones de login sospechoso.

## Capabilities

### New Capabilities

- `session-concurrency-control`: Límite configurable de sesiones simultáneas por usuario; bloqueo en login cuando se supera el límite.
- `session-lifetime-control`: Tiempo de expiración de sesión configurable globalmente y por usuario por el admin.
- `session-visibility-admin`: Panel admin para ver todas las sesiones activas, filtrar por usuario y cerrarlas remotamente.
- `session-visibility-user`: Vista de sesiones propias del usuario con opción de cierre individual o masivo.
- `redis-monitor`: Panel admin para monitorear el estado operativo de Redis (conexión, memoria, sesiones activas, desync con DB).

### Modified Capabilities

- `login-by-username`: El flujo de login ahora verifica límite de sesiones y registra la sesión en `user_sessions`.

## Impact

- **Controllers**: `AuthController` (login, logout), nuevo `SessionController`, nuevo `RedisMonitorController`.
- **Middleware**: nuevo `SessionTracker` registrado en el stack de rutas autenticadas.
- **Models**: `User` (nuevas columnas), nuevo `UserSession`, nuevo `SystemSetting`.
- **Routes**: `/admin/sessions`, `/admin/redis`, `/user/sessions`, extensión de `/admin/users`.
- **Migrations**: 3 nuevas (tabla `user_sessions`, tabla `system_settings`, columnas en `users`).
- **Redis**: lectura de keys de sesión y uso de `Redis::del()` para invalidación remota.
