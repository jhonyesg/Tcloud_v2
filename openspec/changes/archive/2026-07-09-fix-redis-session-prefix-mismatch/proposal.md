## Why

Las sesiones de usuario se cerraban a los ~30 minutos de forma inesperada. La causa raíz fue un mismatch de prefijo en Redis: Laravel guarda las sesiones con el prefijo del cache store (`tcloud_cache_`) usando la conexión `default` (DB 0), pero `SessionService::cleanOrphans()` y otros métodos buscaban con `Cache::has()` (que usa el cache store `redis` apuntando a DB 1) o `Redis::exists()` (que solo aplicaba el prefijo de conexión `tcloud_`). Ninguno encontraba las claves de sesión, así que `cleanOrphans()` borraba todas las sesiones de `user_sessions` cada 30 min — el siguiente request del usuario no encontraba el registro, hacía `Session::flush()`, y redirigía a login.

## What Changes

- **Nuevo helper `sessionExistsInRedis()`** en `SessionService` que busca sesiones en `Redis::connection('default')` con el prefijo del cache store (`config('cache.prefix')`), produciendo la clave correcta `tcloud_tcloud_cache_{session_id}`.
- **`cleanOrphans()`** usa el helper en vez de `Cache::has()` (que buscaba en DB 1).
- **`countActiveSessions()`** usa el helper en vez de `Cache::has()`.
- **`killSession()`** elimina la sesión de `Redis::connection('default')` con el prefijo correcto en vez de `Cache::forget()` (que borraba en DB 1).

## Capabilities

### New Capabilities
<!-- Ninguna — se modifica un spec existente -->

### Modified Capabilities
- `session-tracker-cache`: el `cleanOrphans` ahora busca sesiones con el prefijo y conexión correctos de Redis, evitando falsos negativos que destruían sesiones activas.

## Impact

- `app/app/Services/SessionService.php` — nuevo helper `sessionExistsInRedis()`, fixes en `cleanOrphans()`, `countActiveSessions()`, `killSession()`.
- **No requiere migración** — solo cambio de código.
- **BREAKING**: ninguno. Las sesiones existentes en Redis no se ven afectadas.

## Non-goals

- No se cambia el driver de sesión ni la arquitectura del cache store.
- No se elimina `cleanOrphans()` — se arregla para que busque correctamente.
- No se cambia el `session.save_handler=redis` de PHP nativo (no está interfiriendo).
- No se aborda `SESSION_SECURE_COOKIE` (mejora separada).