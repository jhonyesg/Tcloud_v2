## Why

La sesión de usuario se cierra a los 120 minutos del **login** sin importar la actividad. El `SessionTracker` renueva `last_activity_at` en cada request pero **nunca renueva `expires_at`** en `user_sessions`, que se fija una sola vez en `SessionService::createSession()`. El spec `session-ux` ya asume un timeout deslizante (el ping refresca la sesión), pero el backend no lo cumple: la capa DB mata la sesión a las 2h exactas aunque el usuario haya estado navegando todo el tiempo.

## What Changes

- **Renovar `expires_at`** en `SessionTracker` al detectar actividad: cuando se actualiza `last_activity_at`, recalcular `expires_at = now + lifetime` (ventana deslizante por inactividad, no timeout absoluto).
- **Aumentar `SESSION_LIFETIME`** de 120 (2h) a 1440 (24h) para que la ventana deslizante sea de inactividad real, no 2h.
- **Invalidar caché `session_valid`** tras renovar `expires_at` para evitar que un request posterior use un valor cacheado con `expires_at` ya vencido.
- **No requiere migración**: el campo `expires_at` ya existe en `user_sessions`.

## Capabilities

### New Capabilities
<!-- Ninguna — se modifican specs existentes -->

### Modified Capabilities
- `session-ux`: el requirement de keep-alive ping pasa de "refresca la sesión en Redis" a "renueva `expires_at` en BD + Redis" — ahora el backend realmente extiende la sesión.
- `session-tracker-cache`: el `SessionTracker` ahora también renueva `expires_at` al actualizar `last_activity_at`, e invalida la caché `session_valid` al hacerlo.

## Impact

- `app/app/Http/Middleware/SessionTracker.php` — añadir renovación de `expires_at` + invalidación de caché.
- `app/app/Services/SessionService.php` — método helper `renewExpiry(UserSession $session)` opcional.
- `app/.env` y `app/.env.example` — `SESSION_LIFETIME` de 120 a 1440.
- `app/config/session.php` — sin cambios (ya lee de env).
- `SessionService::countActiveSessions()` y `cleanExpired()` siguen funcionando porque ya comparan contra `expires_at`.
- **BREAKING**: sesiones que ya existían con `expires_at` fijo de 2h seguirán expirando a su hora original; tras un nuevo login obtendrán ventana deslizante de 24h.

## Non-goals

- No se implementa "remember me" persistente entre cierres de navegador.
- No se cambia el driver de sesión (sigue Redis).
- No se añade UI para configurar el lifetime por usuario (el campo `session_lifetime_minutes` ya existe en `User` pero este cambio no lo expone).
- No se aborda el `SESSION_SECURE_COOKIE` faltante (queda como mejora separada).