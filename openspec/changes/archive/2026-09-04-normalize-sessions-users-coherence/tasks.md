## 1. Relación ORM User ↔ UserSession

- [x] 1.1 Añadir `sessions(): HasMany` en `app/app/Models/User.php` (después de la última relación existente, ~línea 187).
- [x] 1.2 Refactorizar `SessionService::countActiveSessions()` (línea 42) para usar `$user->sessions()`.
- [x] 1.3 Refactorizar `SessionService::killAllUserSessions()` (línea 97) para usar `$user->sessions()`.
- [x] 1.4 Refactorizar `UserSessionController::index()` (línea 19) para usar `$user->sessions()`.

## 2. Validación de users.status en SessionTracker

- [x] 2.1 En `app/app/Http/Middleware/SessionTracker.php`, añadir el bloque de verificación de `User::find($record->user_id)?->isActive()` justo después del check de expiración (~línea 50).
- [x] 2.2 Si `isActive()` retorna `false`, llamar a `$this->sessionService->killSession($record)`, luego `Session::flush()` y responder igual que cuando la sesión no existe (JSON 401 / redirect a `/login`).
- [x] 2.3 Confirmar que la lógica del cache `session_valid:{session_id}` se mantiene: si todo es válido, `Cache::put(..., '1', 30)` cubre también el caso de status válido (misma key, mismo TTL).

## 3. Eviction proactiva al cambiar status

- [x] 3.1 En `app/app/Http/Controllers/UserController.php::update()` (línea 105), detectar si el request trae `status` y ese valor es distinto de `User::STATUS_ACTIVE`.
- [x] 3.2 Si la condición se cumple, llamar `$this->sessionService->killAllUserSessions($user)` ANTES de `$user->update($data)`.
- [x] 3.3 Validar manualmente con un usuario de prueba: login → `PATCH /admin/users/{id}` con `{"status": "disabled"}` → siguiente request del usuario devuelve 401.

## 4. Observer para limpieza de Redis al eliminar User

- [x] 4.1 Crear `app/app/Observers/UserObserver.php` con método público `deleting(User $user): void` que itere `$user->sessions()->get()` y llame `SessionService::killSession($session)` para cada uno.
- [x] 4.2 Capturar excepciones internas de Redis con `try/catch` y `Log::warning` (mismo patrón que `SessionService::killSession` ya usa).
- [x] 4.3 Registrar el observer en `AppServiceProvider::boot()` con `User::observe(UserObserver::class)`. Verificar primero si `AppServiceProvider` ya tiene otros observers registrados para mantener consistencia.
- [x] 4.4 Validar manualmente: crear usuario de prueba, hacer login, ejecutar `DELETE /admin/users/{id}` y comprobar con `redis-cli` que las claves `tcloud_tcloud_cache_{session_id}` y `session_valid:{session_id}` ya no existen.

## 5. Verificación final

- [x] 5.1 Ejecutar `php artisan route:list` y confirmar que no se rompió ninguna ruta existente.
- [x] 5.2 Ejecutar la suite de tests existente (`composer test` o equivalente definido en `phpunit.xml`) y confirmar que no hay regresiones.
- [x] 5.3 Manual: login normal → navegar 5 min → confirmar que `session_valid:*` se setea y que el flujo no se ve afectado.
- [x] 5.4 Manual: revisar `app/storage/logs/laravel.log` por warnings de Redis inesperados tras las pruebas anteriores.
