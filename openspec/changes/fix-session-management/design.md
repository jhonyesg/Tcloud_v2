## Context

El sistema de sesiones registra cada login en `user_sessions` (tabla DB) con un `session_id` que corresponde a la clave en Redis donde Laravel almacena los datos de sesión (con prefijo `tcloud_`). El middleware `SessionTracker` usa esta tabla como fuente de verdad para expiración y throttling de actividad, pero tiene un hueco: cuando no encuentra registro en DB, simplemente deja pasar la request sin validar si el usuario debería estar autenticado.

**Estado actual problemático:**
```
SessionTracker:
  if (!$record) → return $next($request)   ← pasa aunque user_id existe en sesión

countActiveSessions():
  WHERE expires_at IS NULL OR expires_at > now()  ← cuenta huérfanos sin Redis activo
```

## Goals / Non-Goals

**Goals:**
- DB `user_sessions` es la única fuente de verdad: sin registro → sin acceso
- El límite de sesiones simultáneas se aplica sobre conexiones con clave activa en Redis
- Matar una sesión desde el admin expulsa efectivamente al usuario en la próxima request
- La tabla `user_sessions` se mantiene limpia automáticamente

**Non-Goals:**
- Logout en tiempo real vía WebSocket/SSE (el expulso ocurre en la próxima request HTTP)
- Cambios en la UI del panel de sesiones (`admin/sessions.blade.php`)
- Cambios en el sistema de roles o permisos

## Decisions

### 1. `SessionTracker`: tratar sesión sin registro DB como inválida

**Decisión**: cuando `Session::has('user_id')` es `true` pero no existe `UserSession` con ese `session_id`, hacer flush + redirect a `/login`.

**Alternativa descartada**: solo pasar sin hacer nada (comportamiento actual) — rompe la garantía de que DB es fuente de verdad.

**Rationale**: el caso "user_id en sesión pero sin registro DB" solo ocurre si (a) el registro fue eliminado manualmente/por admin, o (b) la sesión es un legado pre-sistema. En ambos casos, la respuesta correcta es forzar re-login.

```
Antes:  !$record → return $next($request)
Después: !$record && Session::has('user_id') → Session::flush() → redirect('/login')
         !$record && !Session::has('user_id') → return $next($request)   // ruta pública
```

### 2. `countActiveSessions()`: verificar Redis antes de contar

**Decisión**: para cada `UserSession` en DB que no haya expirado, verificar `Redis::exists($session_id)`. Solo contar las que existen en Redis.

**Alternativa descartada**: limpiar huérfanos en el momento del conteo (delete en DB) — efecto colateral en una lectura, puede generar race conditions.

**Alternativa descartada**: contar solo por DB (actual) — cuenta registros de sesiones donde el cookie ya expiró en el browser.

**Rationale**: Redis es la fuente real de actividad. Si la clave no existe, el usuario ya no tiene esa sesión activa aunque el registro DB permanezca. El conteo con Redis::exists() es O(n) sobre las sesiones del usuario — con límite global de 6 sesiones, n es muy pequeño.

**Fallback**: si Redis lanza excepción, contar el registro como activo (conservador — preferimos falso positivo a dejar entrar sesiones de más).

### 3. `killSession()`: eliminar clave Redis con prefijo correcto

**Decisión**: el prefijo `tcloud_` se aplica automáticamente por la façade `Redis` cuando el driver phpredis tiene `options.prefix`. La llamada `Redis::del($sessionId)` ya produce `DEL tcloud_<session_id>` internamente. No requiere cambio de lógica, pero sí verificar que no se estaba silenciando un error de conexión.

**Mejora**: mantener el try/catch pero loggear la excepción en lugar de silenciarla, para detectar fallos de Redis.

### 4. Scheduled cleanup cada 30 minutos

**Decisión**: agregar en `routes/console.php` un Schedule que llame a `cleanOrphans()` y `cleanExpired()` cada 30 minutos.

**Alternativa descartada**: artisan command separado — innecesario para lógica de 2 líneas ya existente en `SessionService`.

**Rationale**: 30 minutos es suficiente para que la tabla no crezca indefinidamente, sin añadir carga notable al servidor.

## Risks / Trade-offs

| Riesgo | Mitigación |
|--------|-----------|
| Redis lento/down hace que `countActiveSessions()` sea conservador (cuenta todo) | El fallback a contar como activo es la opción segura; el usuario simplemente no puede abrir más sesiones hasta que Redis responda |
| Un usuario es expulsado en la próxima request, no instantáneamente | Comportamiento esperado en auth basada en sesión HTTP; documentar en UI |
| `cleanOrphans()` llama Redis::exists() en loop — si hay muchos usuarios con muchas sesiones inactivas, puede ser lento | Con el límite de sesiones (6 por defecto) la cantidad de registros por usuario es acotada; la limpieza cada 30 min es aceptable |

## Migration Plan

1. Deploy directo, sin migraciones de esquema
2. Al hacer deploy, las sesiones en DB sin clave Redis quedan "huérfanas" — en la próxima request de esos usuarios, `SessionTracker` los expulsará (si tienen user_id en cookie pero sin registro DB, son sesiones viejas sin registro; si tienen registro DB pero sin Redis, el registro se limpiará en el próximo cleanup)
3. Rollback: revertir los 3 archivos modificados (`SessionTracker`, `SessionService`, `console.php`)
