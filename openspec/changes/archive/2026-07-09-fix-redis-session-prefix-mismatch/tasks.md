## 1. Backend — Helper y fixes en SessionService

- [x] 1.1 Crear helper privado `sessionExistsInRedis(string $sessionId): bool` en `SessionService` que use `Redis::connection('default')->exists(config('cache.prefix') . $sessionId)`.
- [x] 1.2 Reemplazar `Cache::has()` por `$this->sessionExistsInRedis()` en `cleanOrphans()`.
- [x] 1.3 Reemplazar `Cache::has()` por `$this->sessionExistsInRedis()` en `countActiveSessions()`.
- [x] 1.4 Reemplazar `Cache::forget()` por `Redis::connection('default')->del(config('cache.prefix') . $sessionId)` en `killSession()`.

## 2. Verificación

- [x] 2.1 Ejecutar `cleanOrphans()` manualmente y verificar que borra 0 sesiones activas.
- [x] 2.2 Hacer login, verificar que la sesión está en Redis (DB 0) con `user_id` y TTL 24h.
- [x] 2.3 Esperar 30+ min, hacer una acción, y confirmar que la sesión NO se cierra.
- [x] 2.4 Verificar que `countActiveSessions()` retorna el número correcto de sesiones activas.