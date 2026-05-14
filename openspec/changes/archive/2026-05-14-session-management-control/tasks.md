## 1. Migraciones de Base de Datos

- [x] 1.1 [DB] Crear migración `create_system_settings_table` con columnas `id`, `key` (unique), `value`, `updated_at` y seeder con `global_max_sessions=6` y `global_session_lifetime=120`
- [x] 1.2 [DB] Crear migración `add_session_fields_to_users_table` que agrega `max_sessions INT NOT NULL DEFAULT 6` y `session_lifetime_minutes INT NULL` a la tabla `users`
- [x] 1.3 [DB] Crear migración `create_user_sessions_table` con columnas `id`, `user_id` (FK cascade), `session_id` (unique), `ip_address`, `user_agent`, `created_at`, `last_activity_at`, `expires_at` (nullable)
- [x] 1.4 Ejecutar las 3 migraciones en el entorno de desarrollo y verificar la estructura en PostgreSQL

## 2. Modelos y Servicio Central

- [x] 2.1 Crear modelo `app/Models/SystemSetting.php` con método estático `get(key, default)` y `set(key, value)` para acceder a `system_settings`
- [x] 2.2 Crear modelo `app/Models/UserSession.php` con `fillable`, relación `belongsTo(User)` e índice por `session_id`
- [x] 2.3 Crear `app/Services/SessionService.php` con métodos: `getEffectiveMaxSessions(User)`, `getEffectiveLifetime(User)`, `countActiveSessions(User)`, `createSession(User, Request)`, `killSession(UserSession)`, `killAllUserSessions(User, exceptSessionId)`, `cleanExpired()`, `cleanOrphans()`
- [x] 2.4 Agregar columnas `max_sessions` y `session_lifetime_minutes` a `$fillable` del modelo `User`

## 3. Middleware SessionTracker

- [x] 3.1 Crear `app/Http/Middleware/SessionTracker.php` que: obtiene el registro de `user_sessions` por `Session::getId()`, verifica `expires_at`, redirige a login si expirada, actualiza `last_activity_at` con throttle de 60s
- [x] 3.2 Registrar `SessionTracker` en `bootstrap/app.php` dentro del grupo de middleware `auth` (después de `Authenticate`)

## 4. Modificación del Flujo de Login y Logout

- [x] 4.1 Modificar `AuthController::login()`: antes de crear sesión, verificar límite con `SessionService::countActiveSessions()` vs `getEffectiveMaxSessions()`; retornar error si se supera
- [x] 4.2 Modificar `AuthController::login()`: después de `Session::put()`, llamar a `SessionService::createSession()` con `Request` para insertar en `user_sessions`
- [x] 4.3 Modificar `AuthController::logout()`: llamar a `SessionService::killSession()` con el registro actual antes de `Session::flush()`

## 5. Panel Admin — Sesiones

- [x] 5.1 Crear `app/Http/Controllers/SessionController.php` con métodos: `index()` (lista paginada de `user_sessions` con `user`), `destroy(UserSession)` (kill individual), `destroyByUser(User)` (kill todas), `globalSettings()` (GET), `updateGlobalSettings(Request)` (POST)
- [x] 5.2 Agregar rutas en `routes/web.php` bajo prefijo `/admin` con middleware `['auth', 'admin']`: `GET /sessions`, `DELETE /sessions/{session}`, `DELETE /sessions/user/{user}`, `GET /sessions/settings`, `POST /sessions/settings`
- [x] 5.3 Crear vista `resources/views/admin/sessions.blade.php` con: tabla de sesiones activas (Alpine.js, fetch a endpoint JSON), filtro por usuario client-side, botones Cerrar individual y "Cerrar todas" por usuario, formulario de configuración global en la misma página
- [x] 5.4 Agregar enlace "Sesiones" en el menú de administración del layout (`resources/views/layouts/app.blade.php`)

## 6. Panel Admin — Modal de Usuario (límites por usuario)

- [x] 6.1 Extender el endpoint `PUT /admin/users/{id}` en `PostgresAdminController` (o el controller de usuarios) para aceptar y guardar `max_sessions` y `session_lifetime_minutes`
- [x] 6.2 Agregar campos `max_sessions` y `session_lifetime_minutes` al modal de edición de usuario en `resources/views/admin/users.blade.php` con labels descriptivos ("0 = sin límite", "vacío = usa global")

## 7. Panel Admin — Monitor Redis

- [x] 7.1 Crear `app/Http/Controllers/RedisMonitorController.php` con: `index()` (vista), `status()` (JSON con info de Redis via `Redis::connection()->client()->info()`, conteo de keys de sesión, conteo de `user_sessions` en DB), `cleanExpired()` (POST), `cleanOrphans()` (POST)
- [x] 7.2 Agregar rutas en `routes/web.php` bajo prefijo `/admin` con middleware `['auth', 'admin']`: `GET /redis`, `GET /redis/status`, `POST /redis/clean-expired`, `POST /redis/clean-orphans`
- [x] 7.3 Crear vista `resources/views/admin/redis.blade.php` con: tarjeta de estado de conexión (verde/rojo), tarjetas de memoria, clientes conectados, uptime, panel de sesiones Redis vs DB con indicador de desync, botones de limpieza con confirmación (Alpine.js)
- [x] 7.4 Agregar enlace "Redis" en el menú de administración del layout

## 8. Vista Usuario — Mis Sesiones

- [x] 8.1 Crear `app/Http/Controllers/UserSessionController.php` con: `index()` (JSON de sesiones propias), `destroy(UserSession)` (kill individual, verifica ownership y que no sea la actual), `destroyOthers()` (kill todas excepto la actual)
- [x] 8.2 Agregar rutas en `routes/web.php` bajo middleware `auth`: `GET /user/sessions`, `DELETE /user/sessions/{session}`, `DELETE /user/sessions`
- [x] 8.3 Agregar sección "Mis Sesiones" en `resources/views/dashboard/user.blade.php`: tabla con IP, dispositivo, inicio, última actividad, badge "Esta sesión" en la actual, botón Cerrar en las demás (Alpine.js, fetch), botón "Cerrar todas las otras sesiones"

## 9. Verificación y Pruebas Manuales

- [x] 9.1 Verificar flujo completo: login → aparece en `user_sessions` → `SessionTracker` actualiza `last_activity_at` → logout elimina registro
- [x] 9.2 Verificar bloqueo de login al llegar al límite: crear 6 sesiones para un usuario, verificar que el 7mo intento muestra el mensaje de error correcto
- [x] 9.3 Verificar expiración: configurar `session_lifetime_minutes = 1` para un usuario, esperar 1 minuto, hacer un request y verificar redirección a login con mensaje de expiración
- [ ] 9.4 Verificar cierre remoto admin: cerrar sesión de usuario desde `/admin/sessions`, verificar que el usuario queda deslogueado en su próximo request
- [ ] 9.5 Verificar monitor Redis: estado de conexión, conteos, limpieza de huérfanas y expiradas
- [ ] 9.6 Verificar que usuario solo ve y cierra sus propias sesiones (no las de otros)
