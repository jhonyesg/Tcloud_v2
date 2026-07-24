## Why

Las sesiones de usuario se cierran prematuramente (~30 min) por dos bugs: `SessionService::cleanOrphans()` busca las sesiones en Redis con el prefijo incorrecto (`tcloud_{id}` en vez de `tcloud_tcloud_cache_{id}`), no las encuentra, y borra el registro de `user_sessions` cada 30 min — el siguiente request del usuario recibe "sesión inválida" y es redirigido al login. Adicionalmente, `global_session_lifetime=480` en `system_settings` ignora el `SESSION_LIFETIME=1440` del `.env`, por lo que el lifetime efectivo sigue siendo 8h, no 24h.

## What Changes

- **Fix `SessionService::cleanOrphans()`**: buscar sesiones en Redis usando el mismo prefijo que el cache store (`tcloud_cache_`), no el prefijo de la conexión Redis (`tcloud_`). La clave correcta es `{redis_prefix}{cache_prefix}{session_id}` = `tcloud_tcloud_cache_{session_id}`.
- **Actualizar `global_session_lifetime` en `system_settings`** de 480 a 1440 (24h) para alinear con `SESSION_LIFETIME` del `.env`.
- **Actualizar `.env.example`**: documentar que `SESSION_LIFETIME` es solo el default de Laravel y que `global_session_lifetime` en `system_settings` toma precedencia.

## Capabilities

### New Capabilities
<!-- Ninguna — se modifican specs existentes -->

### Modified Capabilities
- `session-tracker-cache`: el `cleanOrphans` debe buscar sesiones en Redis con el prefijo correcto del cache store, no destruir sesiones válidas por un falso negativo.

## Impact

- `app/app/Services/SessionService.php` — método `cleanOrphans()`: usar el prefijo del cache store para buscar en Redis, o usar el `Cache::has()` que ya resuelve el prefijo automáticamente.
- `system_settings` tabla — actualizar `global_session_lifetime` de 480 a 1440 vía seeder/migration.
- `app/.env.example` — nota sobre la relación entre `SESSION_LIFETIME` y `global_session_lifetime`.
- **No requiere migración de schema**: el campo `value` de `system_settings` es un string, solo se actualiza el valor.

## Non-goals

- No se cambia el driver de sesión (sigue Redis via cache store).
- No se elimina el `cleanOrphans()` — se arregla para que busque correctamente.
- No se aborda `SESSION_SECURE_COOKIE` (mejora separada).
- No se cambia el `session.save_handler=redis` de PHP nativo (no está interfiriendo).