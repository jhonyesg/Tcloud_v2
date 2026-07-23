## 1. Backend — Renovación deslizante de `expires_at`

- [x] 1.1 Modificar `app/app/Http/Middleware/SessionTracker.php`: inyectar `SessionService`, y en el bloque de throttle de 60s (líneas 55-56) actualizar también `expires_at = now()->addMinutes($lifetime)` usando `SessionService::getEffectiveLifetimeMinutes(User::find(session('user_id')))`. Si lifetime=0, dejar `expires_at = null` (no expira).
- [x] 1.2 En el mismo bloque, tras el `update()`, invalidar el caché con `Cache::forget("session_valid:{$sessionId}")`.
- [x] 1.3 Verificar que el `update()` haga ambos campos en una sola query: `$record->update(['last_activity_at' => now(), 'expires_at' => $newExpiry])`.

## 2. Configuración — `SESSION_LIFETIME` a 24h

- [x] 2.1 Cambiar `SESSION_LIFETIME=120` a `SESSION_LIFETIME=1440` en `app/.env`.
- [x] 2.2 Cambiar `SESSION_LIFETIME=120` a `SESSION_LIFETIME=1440` en `app/.env.example`.

## 3. Verificación

- [x] 3.1 Hacer login, navegar por 3+ minutos, y verificar en `user_sessions` que `expires_at` se renovó (ya no es login+2h sino última_actividad+24h).
- [x] 3.2 Verificar que `SessionService::countActiveSessions()` y `cleanExpired()` siguen funcionando con `expires_at` renovado.
- [x] 3.3 Verificar que el ping cada 30 min del `session-manager.js` renueva `expires_at` al pasar por el `SessionTracker`.
- [x] 3.4 Verificar que un usuario con `session_lifetime_minutes = 0` (ilimitado) no recibe `expires_at` renovado (queda null).