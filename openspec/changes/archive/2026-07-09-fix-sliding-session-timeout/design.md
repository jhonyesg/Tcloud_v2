## Context

TCloud usa un esquema de sesión de **doble capa**: Redis (cookie de Laravel con `SESSION_LIFETIME=120`, ya deslizante) + tabla `user_sessions` en PostgreSQL con `expires_at` (timeout absoluto, nunca renovado). El middleware `SessionTracker` valida en cada request el registro DB y actualiza `last_activity_at` cada 60s, pero deja `expires_at` intacto. Resultado: la sesión muere a las 2h del login sin importar actividad.

El spec `session-ux` ya describe un ping cada 30 min que "extiende la sesión en Redis", pero el backend nunca renovó `expires_at` en DB, por lo que la capa DB mata la sesión antes.

```
         ESTADO ACTUAL (timeout absoluto)
         ══════════════════════════════════

    login                    +120min            muerte
      │◄────────────────────────►│              ✗
      │       actividad              │
      │  (last_activity sube,        │
      │   expires_at NO cambia)     ▼
      │                        isExpired()=true
      │                        → killSession()
      │                        → redirect /login

         ESTADO DESEADO (timeout deslizante 24h)
         ════════════════════════════════════════

    login   +1h   +2h   ...   inactividad 24h   muerte
      │      │     │           │                 ✗
      │      ▼     ▼           ▼
      │   renew  renew       (sin requests)
      │   exp=+24h exp=+24h
      │                              ▲
      │                         expires_at.isPast()
```

## Goals / Non-Goals

**Goals:**
- Convertir el timeout de sesión de absoluto (2h desde login) a deslizante (24h desde última actividad).
- Renovar `expires_at` en `user_sessions` en cada request activo, no solo `last_activity_at`.
- Mantener el cache `session_valid` consistente tras la renovación.
- Mínimo impacto: sin migración, sin nuevo modelo, sin cambios de frontend.

**Non-Goals:**
- No se añade "remember me" persistente entre cierres de navegador.
- No se cambia el driver de sesión (sigue Redis).
- No se expone configuración de lifetime por usuario en UI.
- No se aborda `SESSION_SECURE_COOKIE` (mejora separada).

## Decisions

### D1: Renovar `expires_at` en el `SessionTracker`, no en el `SessionService::createSession`

**Decisión:** Modificar `SessionTracker.php` para que, al detectar actividad (mismo throttle de 60s que `last_activity_at`), recalcule `expires_at = now() + lifetimeMinutes` y persista ambos campos en un solo `update()`.

**Rationale:** El tracker ya tiene el `UserSession` cargado y ya decide cuándo actualizar `last_activity_at`. Renovar `expires_at` en el mismo punto minimiza queries adicionales (un solo `update` con dos campos) y centraliza la lógica de "actividad detectada".

**Alternativa descartada:** Crear un método `SessionService::renewExpiry()` y llamarlo desde el tracker. Más limpio arquitectónicamente pero añade una indirección innecesaria para 1 línea de lógica. Se opta por inline en el tracker.

### D2: Obtener `lifetimeMinutes` dentro del tracker vía `SessionService`

**Decisión:** Inyectar `SessionService` en `SessionTracker` (ya está disponible como alias) y usar `getEffectiveLifetimeMinutes($user)` para recalcular `expires_at`. Esto respeta el override por usuario (`session_lifetime_minutes`) y el setting global.

**Rationale:** Si hardcodeda el `SESSION_LIFETIME` del env, se ignoraría el override por usuario. Reutilizar `getEffectiveLifetimeMinutes` mantiene consistencia con `createSession`.

**Alternativa descartada:** Leer `config('session.lifetime')` directo. Rompería el override por usuario que ya existe en `User::session_lifetime_minutes` y `SystemSetting::global_session_lifetime`.

### D3: Throttle de renovación cada 60s, igual que `last_activity_at`

**Decisión:** Renovar `expires_at` solo cuando `last_activity_at->diffInSeconds(now()) >= 60`, reutilizando el mismo bloque condicional existente. Un solo `update(['last_activity_at' => now(), 'expires_at' => $newExpiry])`.

**Rationale:** Renovar en cada request generaría un `UPDATE` por request (decenas por segundo en uso activo). Con throttle de 60s, como máximo 1 update/min. La ventana de 60s de holgura es despreciable frente a un lifetime de 24h.

**Alternativa descartada:** Renovar en cada request. Sobrecarga innecesaria de DB.

### D4: Invalidar `session_valid` cache tras renovación

**Decisión:** Tras el `update()` de `expires_at`, ejecutar `Cache::forget("session_valid:{$sessionId}")` para que el próximo request revalide contra DB y vea el `expires_at` nuevo.

**Rationale:** Sin esto, un request dentro de los 30s de TTL del cache usaría el `expires_at` viejo. Como el tracker ya pasó la validación en este request, el riesgo es bajo, pero por consistencia se invalida. El costo es 1 `DEL` en Redis, despreciable.

**Alternativa descartada:** No invalidar y dejar que el TTL de 30s expire naturalmente. Funciona, pero deja una ventana de 30s donde la caché "sabe" que la sesión es válida con un `expires_at` ya renovado en DB. No causa bug (la caché solo guarda `'1'`, no el `expires_at`), pero la invalidación es más correcta y barata.

### D5: `SESSION_LIFETIME` 120 → 1440 (24h)

**Decisión:** Cambiar `SESSION_LIFETIME` en `.env` y `.env.example` de 120 a 1440.

**Rationale:** Con timeout deslizante, `SESSION_LIFETIME` pasa a significar "tiempo de inactividad permitido", no "duración máxima de sesión". 24h cubre el caso de uso descrito ("no cerrar a menos que pasen 24-48h"). Se elige 24h como punto medio conservador; si el usuario quiere 48h, es un solo cambio de env.

**Alternativa descartada:** 2880 (48h). Más generoso pero aumenta el riesgo de sesiones zombies si el usuario cierra el tab sin logout. 24h es un buen equilibrio.

### D6: Ping del frontend ya existente queda funcional sin cambios

**Decisión:** No tocar `session-manager.js` ni el endpoint `POST /auth/ping`. El ping ya dispara un request que pasa por `SessionTracker`, que ahora renovará `expires_at` automáticamente.

**Rationale:** El ping cada 30 min ya está diseñado para mantener viva la sesión en Redis. Con la renovación de `expires_at` en el tracker, el ping también refresca la capa DB. Cero cambios de frontend.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Sesiones existentes con `expires_at` de 2h siguen muriendo a su hora original | No se puede migrar en caliente; tras logout/login obtienen la nueva ventana. Aceptable. |
| Si `getEffectiveLifetimeMinutes` retorna 0 (lifetime ilimitado), `expires_at` sería null y la sesión nunca expira | `createSession` ya maneja este caso (`$expiresAt = $lifetimeMinutes > 0 ? ... : null`). El tracker debe respetarlo: si lifetime=0, no renovar (dejar null). |
| Un `update` extra cada 60s por sesión activa aumenta writes en Postgres | Despreciable: 1 update/min/sesión vs las decenas de reads que ya hace. Postgres maneja esto sin problema. |
| `SessionService::countActiveSessions()` y `cleanExpired()` ya comparan contra `expires_at` | Funcionan correctamente sin cambios: ahora `expires_at` refleja actividad real, no creación. |
| Si Redis reinicia, la cookie de Laravel muere pero `user_sessions` queda como huérfano | `cleanOrphans()` ya existe y limpia registros sin clave Redis. Sin cambios necesarios. |