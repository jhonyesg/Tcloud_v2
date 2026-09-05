## Why

El módulo `user_sessions` convive con `users` desde mayo 2026, pero la coherencia entre ambos quedó incompleta: el modelo `User` no expone la relación inversa `sessions()`, el middleware `SessionTracker` no consulta el `status` actual del usuario (un cambio a `disabled` o `pending` no invalida sesiones vivas), y `UserController::destroy()` deja keys huérfanas en Redis porque la limpieza es responsabilidad de `SessionService::killSession()`, no del cascade de DB. Estos tres huecos son deuda que crece con cada uso nuevo y compromete el futuro uso de `status=disabled` como mecanismo de bloqueo.

## What Changes

- Añadir la relación `User::sessions()` (`HasMany UserSession`) en `app/app/Models/User.php`, simétrica con la ya existente `UserSession::user()`. Refactorizar los 5 sitios que hoy hacen `UserSession::where('user_id', ...)` a usar la relación.
- Añadir verificación de `User::isActive()` dentro del middleware `SessionTracker` (`app/app/Http/Middleware/SessionTracker.php`) tras el check de expiración, con cache de 30 s para no duplicar queries. Si el `status` del usuario no es `active`, la sesión debe ser matada (`SessionService::killSession`) y el usuario expulsado con 401 + `X-Session-Expired: 1`.
- Añadir limpieza proactiva de sesiones en `UserController::destroy()` y `UserController::update()` cuando el `status` cambie a un valor distinto de `active` (defensa en profundidad, no se espera al próximo request).
- Añadir limpieza de keys Redis huérfanas al eliminar un usuario, mediante un observer de Eloquent sobre `User::deleted` que itere las sesiones del usuario y llame a `SessionService::killSession()` para cada una antes de que el cascade de DB las borre.

## Capabilities

### New Capabilities
- `user-sessions-coherence`: define el contrato de coherencia entre `users` y `user_sessions`: relación ORM, eviction de sesiones al cambiar `status`, y limpieza de Redis al eliminar un usuario.

### Modified Capabilities
- `session-tracker-cache`: añadir el requirement de validación de `users.status` dentro del middleware `SessionTracker` (hoy solo verifica existencia en `user_sessions` y expiración).

## Impact

**Código afectado**:
- `app/app/Models/User.php` — nueva relación `sessions()`, refactor del modelo.
- `app/app/Models/UserSession.php` — sin cambios.
- `app/app/Http/Middleware/SessionTracker.php` — verificación de `status` y eviction.
- `app/app/Http/Controllers/UserController.php` — eviction proactiva en `update()` y `destroy()`, registro del observer.
- `app/app/Http/Controllers/SessionController.php` — usar `$session->user` ya cargado vía `with('user')` (sin cambios funcionales).
- `app/app/Http/Controllers/UserSessionController.php` — usar `$user->sessions()`.
- `app/app/Services/SessionService.php` — `countActiveSessions()` y `killAllUserSessions()` pueden aprovechar la relación.
- `app/app/Http/Controllers/RedisMonitorController.php` — usar `$user->sessions()->pluck('session_id')` para el join con Redis.
- `app/app/Observers/UserObserver.php` (nuevo) — limpieza de keys Redis en evento `deleted`.

**APIs/Rutas**: ninguna ruta nueva ni cambio de contrato HTTP. Las respuestas 401 por `status` no-`active` ya están contempladas por el header `X-Session-Expired: 1`.

**Migraciones**: no se requieren nuevas migraciones. La tabla `user_sessions` ya existe con `onDelete('cascade')` desde `2026_05_13_100003_create_user_sessions_table.php`. El observer opera en código, no en schema.

**Riesgos**: el observer `UserObserver::deleted` debe ejecutarse ANTES de que el cascade borre las filas de `user_sessions`, por lo que se debe usar el evento `deleting` (no `deleted`) y consultar `$user->sessions()->get()` antes de que la fila del padre desaparezca. Sin este detalle, el cascade ya habrá borrado las sesiones y no habrá qué limpiar en Redis.

## Non-goals

- No se introduce un nuevo valor `disabled` ni se modifica el ciclo de vida de `status` (ya cubierto por la migración `2026_09_04_120000_add_status_to_users_table.php`). Solo se reacciona a los valores existentes.
- No se cambia el formato del header `X-Session-Expired: 1` ni se añade un nuevo header diferenciador para "sesión matada por status" — el frontend ya trata cualquier 401 con ese header como redirección a login.
- No se introduce un job/cola para limpieza batch de Redis huérfanas históricos; ese cleanup es responsabilidad de `SessionService::cleanOrphans()` ya existente. El observer solo garantiza que los usuarios eliminados a partir de este cambio no dejen nuevos zombies.
- No se modifica el comportamiento del login: el chequeo `if (!$user->isActive())` en `AuthController::login()` ya está implementado y es independiente de este cambio.
