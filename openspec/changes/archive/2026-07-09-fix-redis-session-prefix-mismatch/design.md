## Context

TCloud guarda sesiones de Laravel en Redis a través del driver `redis`, que internamente usa `CacheBasedSessionHandler` con el cache store `redis`. El `SessionManager::createRedisDriver()` hace dos cosas:

1. Crea un handler basado en `cache->store('redis')` — que por defecto usa la conexión `cache` (DB 1)
2. Llama `setConnection('default')` — que cambia la conexión a `default` (DB 0)

Esto significa que las sesiones se guardan en **DB 0** con el prefijo del cache store (`tcloud_cache_`) además del prefijo de la conexión Redis (`tcloud_`), resultando en claves `tcloud_tcloud_cache_{session_id}`.

Sin embargo, los métodos de `SessionService` usaban APIs distintas para verificar/eliminar sesiones, ninguna de las cuales apuntaba al lugar correcto:

```
┌────────────────────────────────────────────────────────────────────┐
│         DISCREPANCIA DE CONEXIÓN Y PREFIJO EN REDIS                │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│  Laravel session handler:                                          │
│    cache->store('redis')->setConnection('default')                 │
│    → escribe en DB 0                                               │
│    → clave: tcloud_tcloud_cache_{sessionId}        ✅ existe      │
│                                                                    │
│  Cache::has() (cache store 'redis'):                               │
│    usa conexión 'cache'                                            │
│    → busca en DB 1                                  ❌ no encuentra │
│                                                                    │
│  Redis::exists($sessionId) (conexión default):                     │
│    aplica solo prefijo de conexión                                 │
│    → busca tcloud_{sessionId}                      ❌ no encuentra │
│                                                                    │
│  Redis::connection('default')->exists(                            │
│    config('cache.prefix') . $sessionId                             │
│  ):                                                                │
│    → busca tcloud_tcloud_cache_{sessionId}         ✅ correcto    │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

El job programado `cleanOrphans()` corría cada 30 minutos. Como nunca encontraba las sesiones en Redis, borraba **todos** los registros de `user_sessions`. El siguiente request del usuario pasaba por `SessionTracker`, no encontraba el registro en la DB, ejecutaba `Session::flush()`, y el `Authenticate` middleware devolvía 401.

## Goals / Non-Goals

**Goals:**
- Centralizar la verificación de existencia de sesión en Redis en un único helper con la conexión y prefijo correctos.
- Arreglar `cleanOrphans()`, `countActiveSessions()`, y `killSession()` para que operen sobre la clave correcta.

**Non-Goals:**
- No se cambia el driver de sesión ni la arquitectura del cache store.
- No se cambia el prefijo del cache store o de la conexión Redis.

## Decisions

### D1: Helper privado `sessionExistsInRedis()` con conexión y prefijo correctos

**Decisión:** Crear un helper `private function sessionExistsInRedis(string $sessionId): bool` que use `Redis::connection('default')->exists(config('cache.prefix') . $sessionId)`. Esto produce la clave `tcloud_tcloud_cache_{sessionId}` que es exactamente donde Laravel guarda las sesiones.

**Rationale:** Es la única combinación que apunta al lugar correcto: conexión `default` (DB 0) + prefijo del cache store (`tcloud_cache_`). Centralizar en un helper evita repetir la lógica y facilita futuros cambios.

**Alternativa descartada:** Usar `Cache::store('redis')->getStore()->setConnection('default')` seguido de `Cache::has()`. Más verboso y crea una instancia modificada del cache store que podría tener efectos colaterales.

### D2: `killSession()` elimina con `Redis::connection('default')->del()`

**Decisión:** Reemplazar `Cache::forget($session->session_id)` con `Redis::connection('default')->del(config('cache.prefix') . $session->session_id)`.

**Rationale:** `Cache::forget()` operaba en DB 1 (cache store default), no en DB 0 donde está la sesión. La sesión nunca se eliminaba de Redis, quedando como zombie hasta que expiraba por TTL.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| Si `config('cache.prefix')` cambia, el helper se ajusta automáticamente | Usa `config()` dinámicamente, no hardcoded. |
| Si `Redis::connection('default')` falla, el try/catch en `cleanOrphans` evita borrar | El catch vacío conserva la sesión (conservador). |
| `countActiveSessions()` ahora cuenta correctamente, podría bloquear logins si hay muchas sesiones activas | Es el comportamiento correcto — antes siempre retornaba 0, nunca bloqueaba. |
| Sesiones zombie en Redis (creadas antes del fix) no se limpian por `cleanOrphans` | Expiran naturalmente por TTL (24h). `cleanOrphans` solo borra registros de DB sin clave en Redis, no viceversa. |