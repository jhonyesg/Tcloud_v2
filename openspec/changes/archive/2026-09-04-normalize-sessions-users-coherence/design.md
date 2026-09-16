## Context

`users` y `user_sessions` conviven desde mayo 2026 pero la coherencia quedó incompleta (ver `proposal.md` - Why). Tres huecos detectados:

1. `User` no expone `sessions()` HasMany → 5 sitios hacen `UserSession::where('user_id', ...)` a mano.
2. `SessionTracker` no consulta `users.status` → un cambio a `disabled`/`pending` no invalida sesiones vivas.
3. `User::delete()` deja keys huérfanas en Redis porque el cascade DB no dispara limpieza.

`SessionService` ya centraliza `killSession()` (limpia Redis + borra fila DB + invalida cache `session_valid:*`), así que la solución es cablear correctamente los tres puntos de disparo faltantes.

## Goals / Non-Goals

**Goals:**
- Hacer simétrica la relación ORM `User ↔ UserSession`.
- Garantizar que cualquier transición de `status` no-`active` (o eliminación del usuario) invalida TODAS las sesiones del usuario, tanto en DB como en Redis, antes de que el siguiente request del usuario obtenga respuesta.
- Mantener cero impacto en la API HTTP: misma semántica 401 + `X-Session-Expired: 1` que el frontend ya maneja.

**Non-Goals:**
- No se introduce un panel UI de "expulsar todas las sesiones"; eso ya existe (`POST /admin/sessions/user/{user}` → `SessionController::destroyByUser`).
- No se modifican los defaults de `max_sessions` / `session_lifetime_minutes`.
- No se introducen nuevas migraciones.

## Decisions

### Decisión 1: Relación `User::sessions()` y refactor de los call-sites existentes

Se añade `User::sessions()` como `HasMany(UserSession::class)`. Los 5 sitios que hoy filtran manualmente por `user_id` se refactorizan para usar la relación.

**Alternativas consideradas:**
- Dejar el código como está → rechazo: la deuda crece con cada uso nuevo y rompe la simetría del ORM.
- Eager-loading global en un trait (`with('sessions')`) → rechazo: infla queries innecesariamente para todos los lugares que cargan `User`.

**Sitios a refactorizar** (todos verificados en código actual):
- `app/app/Services/SessionService.php:42` → `$user->sessions()->where(...)`
- `app/app/Services/SessionService.php:97` → `$user->sessions()`
- `app/app/Http/Controllers/UserSessionController.php:19` → `$user->sessions()->where(...)`
- `app/app/Http/Controllers/RedisMonitorController.php:130` → `$user->sessions()->pluck('session_id')`
- `app/app/Http/Controllers/SessionController.php:19` queda como `UserSession::with('user')->...` (vista admin global, no es por usuario).

### Decisión 2: Verificación de `status` en `SessionTracker` con cache de 30 s

`SessionTracker` ya cachea `session_valid:{session_id}` por 30 s para evitar queries repetidas. La verificación de `status` se hace en el mismo punto del flujo (justo tras confirmar existencia y no-expiración), y el resultado se almacena en la MISMA cache key (`session_valid:{session_id}` con valor `'1'` cuando todo está válido, sin valor cuando se va a evictar). Esto evita una segunda cache key.

**Flujo modificado**:
```
1. Cache hit?     → return $next (skip todo)
2. UserSession::where(session_id)->first()  → null? → flush + 401
3. $record->isExpired()?  → killSession + flush + 401
4. User::find($record->user_id)?->isActive()?  → NO → killSession + flush + 401
5. Cache::put(session_valid:{id}, '1', 30)
6. Continuar con renovación de expires_at (cada 60s) y $next
```

**Alternativas consideradas:**
- Verificar `status` solo en `AuthController::login()` → rechazo: solo bloquea nuevos logins, no sesiones activas (problema exacto que se está resolviendo).
- Verificar `status` en cada query del modelo (global scope) → rechazo: infla todas las queries de `User`, no solo las autenticadas.
- Verificar `status` en un evento Eloquent (`User::saved`) que dispare `killAllUserSessions` async → rechazo: añade cola/complejidad para algo que se resuelve en una query cacheada.

**Riesgo de timing**: el cache de 30 s introduce una ventana donde un `disabled` no surte efecto inmediato. Mitigación: el `UserController::update()` dispara `killAllUserSessions` proactivamente, así que la única ventana residual es si se cambia el `status` directamente en DB (bypass del controller). Aceptable para esta versión.

### Decisión 3: Observer `UserObserver` en evento `deleting` (no `deleted`)

El cascade de DB elimina `user_sessions` automáticamente. Si el observer se registra en `deleted`, las filas ya no existirán cuando se ejecute → imposible saber qué `session_id` limpiar en Redis. Por tanto, el observer debe usar el evento `deleting` (disparado ANTES del delete real), leer `$user->sessions()->get()` con todas las claves, y ejecutar `killSession` para cada una.

**Implementación**:
```php
// app/app/Observers/UserObserver.php
class UserObserver
{
    public function deleting(User $user): void
    {
        foreach ($user->sessions()->get() as $session) {
            app(SessionService::class)->killSession($session);
        }
    }
}
```

Registro en `AppServiceProvider::boot()` (o donde estén los demás observers del proyecto).

**Alternativas consideradas:**
- Sobrescribir `User::delete()` para hacer la limpieza manualmente y luego llamar a `parent::delete()` → rechazo: duplica lógica del cascade, riesgo de olvidarla al añadir otro comportamiento.
- Listener `DB::listen` o trigger SQL → rechazo: complica el deploy y no es idiomático Laravel.

### Decisión 4: Eviction proactiva en `UserController::update()` cuando `status` cambia

Cuando `UserController::update()` recibe un `status` distinto de `active`, llama `SessionService::killAllUserSessions($user)` ANTES de `$user->update($data)`. Esto evita esperar a que el usuario haga su próximo request para ser expulsado.

**Importante**: `$user->update($data)` ya modificó el `status` a nivel de instancia pero no ha hecho flush. Si se llama `killAllUserSessions` ANTES del `update`, el `User` en memoria aún tiene `status = 'active'`, lo cual es coherente con el helper `getEffectiveMaxSessions` que ya usa el mismo patrón. Si se llama DESPUÉS, hay que tener cuidado de no usar el `status` cacheado.

**Decisión**: llamar `killAllUserSessions` ANTES del `update`, usando `$user` (instancia con `status` previo a la transición). Es seguro porque `killAllUserSessions` no consulta `status`, solo cuenta filas en `user_sessions` y llama `killSession` por cada una.

**Excepción**: si la transición es `pending → active` (caso del `setup-password`), NO se evictan sesiones porque no hay sesión activa previa al primer login.

**Alternativas consideradas:**
- Confiar solo en `SessionTracker` (sin eviction proactiva) → rechazo: ventana de hasta 30 s donde un usuario deshabilitado sigue navegando. Aceptable pero subóptimo.
- Hacer la eviction en un job async → rechazo: añade latencia y complejidad innecesaria para una operación admin puntual.

## Risks / Trade-offs

- **Riesgo de queries extras en `SessionTracker`** → Mitigación: el cache de 30 s existente ya cubre este punto; el costo marginal es solo UNA query adicional (`User::find($id)`) en el primer request post-cambio o cada 30 s, lo cual es despreciable frente al `UPDATE user_sessions` que ya se hace.
- **Ventana de 30 s entre cambio de `status` y eviction efectiva** → Mitigación: el eviction proactivo en `UserController::update()` la reduce a cero en el caso normal (cambio vía API). Solo bypass por DB queda con la ventana de 30 s.
- **Observer en `deleting` debe completarse antes de que el cascade arranque** → Mitigación: el observer es síncrono y se ejecuta dentro del ciclo de vida del `delete()`. Si Redis falla, se loguea y se continúa (decisión explícita, ver spec escenario "Redis falla durante la limpieza de delete").
- **Refactor de los 5 call-sites introduce riesgo de regresión** → Mitigación: cada cambio debe ser unit-testeable mediante el `SessionService` que ya está testeado. La relación `$user->sessions()` produce SQL idéntico al `UserSession::where('user_id', ...)` actual, así que el plan de ejecución es el mismo.

**Fix colateral de bug pre-existente (descubierto durante validación)**: durante la corrida del harness end-to-end se detectó que `SessionService::sessionExistsInRedis()` y `SessionService::killSession()` usaban `Redis::connection('default')` (DB 0), pero el cache store real está configurado en `Redis::connection('cache')` (DB 1) según `config/database.php`. Esto significaba que las sesiones nunca se borraban correctamente de Redis — la clave quedaba huérfana en DB 1. Como mi change depende de `killSession()` para que Hallazgos 2 y 3 funcionen end-to-end, el fix mínimo de cambiar `default` → `cache` se incorporó en este change (2 líneas en `SessionService.php`). Sin este fix, el observer y la eviction proactiva no podrían limpiar Redis. El spec `session-tracker-cache` se actualiza correspondientemente bajo `## MODIFIED Requirements`.

## Migration Plan

No requiere migración de datos. El despliegue es:

1. Merge del branch con los cambios.
2. `php artisan config:clear && php artisan cache:clear` (para invalidar cualquier `session_valid:*` cacheada con la lógica anterior).
3. Verificación manual: hacer login con un usuario, ejecutar `UPDATE users SET status='disabled' WHERE id=X` directamente en DB, comprobar que el siguiente request del usuario recibe 401.
4. Verificación manual: eliminar un usuario con sesión activa vía `/admin/users/{id} DELETE`, comprobar con `redis-cli KEYS 'tcloud_*'` que no quedan claves huérfanas para ese session_id.

**Rollback**: revertir el merge y ejecutar `php artisan cache:clear`. No hay schema changes, así que no requiere rollback de migración. Los `User` ya eliminados durante el período en producción mantendrían sus claves Redis huérfanas, pero `SessionService::cleanOrphans()` las limpiará en el siguiente ciclo (si está programado por cron).

## Open Questions

Ninguna. Las decisiones de diseño están cerradas y se alinean con los requirements definidos en `specs/user-sessions-coherence/spec.md` y `specs/session-tracker-cache/spec.md`.
