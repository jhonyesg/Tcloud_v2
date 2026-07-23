## 1. Backend — Fix cleanOrphans

- [x] 1.1 Modificar `app/app/Services/SessionService.php` método `cleanOrphans()`: reemplazar `Redis::exists($session->session_id)` con `Cache::has($session->session_id)`. Añadir `use Illuminate\Support\Facades\Cache;` si no está importado. Mantener el try/catch para que si Redis falla, no se borre el registro.
- [x] 1.2 Verificar que `countActiveSessions()` también usa `Redis::exists($session->session_id)` — evaluar si necesita el mismo fix (usar `Cache::has` para consistencia, o dejarlo si el comportamiento es aceptable: cuenta sesiones activas en Redis con prefijo incorrecto, retorna 0, lo que evita bloquear logins por max_sessions).

## 2. Configuración — global_session_lifetime a 1440

- [x] 2.1 Actualizar `system_settings` donde `key='global_session_lifetime'` de 480 a 1440 vía query SQL directo o seeder: `UPDATE system_settings SET value='1440' WHERE key='global_session_lifetime';`

## 3. Verificación

- [x] 3.1 Hacer login, esperar >30 min, y verificar que `cleanOrphans()` no borró el registro en `user_sessions`.
- [x] 3.2 Verificar que `Cache::has($sessionId)` retorna true para una sesión activa y false para una expirada.
- [x] 3.3 Verificar que `getEffectiveLifetimeMinutes()` retorna 1440 para un usuario sin override.
- [x] 3.4 Hacer login, estar activo >30 min, y confirmar que la sesión NO se cierra.