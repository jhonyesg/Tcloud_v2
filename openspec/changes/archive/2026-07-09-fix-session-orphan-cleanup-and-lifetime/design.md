## Context

TCloud guarda sesiones de Laravel en Redis a través del driver `redis`, que internamente usa el `CacheBasedSessionHandler` con el cache store `redis`. Esto significa que las sesiones se almacenan con el prefijo del cache store (`tcloud_cache_`) además del prefijo de la conexión Redis (`tcloud_`), resultando en claves `tcloud_tcloud_cache_{session_id}`.

El `SessionService::cleanOrphans()` busca sesiones con `Redis::exists($session->session_id)`, que con el prefijo de la conexión Redis produce `tcloud_{session_id}` — sin el `tcloud_cache_` intermedio. Esta búsqueda siempre falla, y `cleanOrphans()` borra todos los registros de `user_sessions` que sí tienen sesión válida en Redis. El job programado corre cada 30 minutos, por lo que cualquier sesión creada hace más de 30 min es candidato a borrado.

```
┌───────────────────────────────────────────────────────────────┐
│           DISCREPANCIA DE PREFIJO EN REDIS                    │
└───────────────────────────────────────────────────────────────┘

  Laravel session handler (CacheBasedSessionHandler):
    cache->put($sessionId, $data, $minutes*60)
    → key = tcloud_tcloud_cache_{sessionId}   ✅ (existe)

  SessionService::cleanOrphans():
    Redis::exists($session->session_id)
    → key = tcloud_{sessionId}                ❌ (no existe)

  Resultado: cleanOrphans() siempre cree que la sesión es huérfana
  y borra el registro de user_sessions cada 30 min.
```

Adicionalmente, `SessionService::getEffectiveLifetimeMinutes()` usa `SystemSetting::get('global_session_lifetime', 120)` que retorna 480 (8h), ignorando el `SESSION_LIFETIME=1440` del `.env`.

## Goals / Non-Goals

**Goals:**
- Arreglar `cleanOrphans()` para que busque sesiones con el prefijo correcto.
- Alinear `global_session_lifetime` con `SESSION_LIFETIME` (1440 min = 24h).
- Asegurar que las sesiones activas no sean borradas por falsos negativos de Redis.

**Non-Goals:**
- No se cambia el driver de sesión ni la arquitectura del cache store.
- No se elimina `cleanOrphans()` — es útil para limpiar sesiones realmente huérfanas.
- No se cambia `session.save_handler=redis` de PHP nativo.

## Decisions

### D1: Usar `Cache::has()` en vez de `Redis::exists()` en `cleanOrphans()`

**Decisión:** Reemplazar `Redis::exists($session->session_id)` con `Cache::has($session->session_id)` en `cleanOrphans()`, ya que el handler de sesión usa el cache store que `Cache::has()` resuelve con el prefijo correcto automáticamente.

**Rationale:** El `CacheBasedSessionHandler` usa `$this->cache->put($sessionId, ...)` que es exactamente `Cache::put($sessionId, ...)`. La forma simétrica de verificar existencia es `Cache::has($sessionId)`, que aplica el mismo prefijo del cache store. `Redis::exists()` usa la conexión Redis cruda con el prefijo de la conexión, no el del cache store.

**Alternativa descartada:** Concatenar manualmente el prefijo del cache store (`tcloud_cache_`) en la llamada a `Redis::exists()`. Frágil: si cambia el `CACHE_PREFIX` o el `REDIS_PREFIX`, se rompe. `Cache::has()` es resiliente.

### D2: Actualizar `global_session_lifetime` a 1440 en `system_settings`

**Decisión:** Ejecutar un seeder o query directo que actualice `system_settings` donde `key='global_session_lifetime'` de 480 a 1440.

**Rationale:** `SessionService::getEffectiveLifetimeMinutes()` usa `SystemSetting::get('global_session_lifetime', 120)`. Sin importar el `.env`, este valor controla el lifetime efectivo. Cambiarlo a 1440 alinea con el `SESSION_LIFETIME=1440` del `.env` y con la ventana deslizante de 24h implementada en el cambio anterior.

**Alternativa descartada:** Hacer que `getEffectiveLifetimeMinutes()` caiga a `config('session.lifetime')` en vez de `SystemSetting`. Más invasivo y rompe el patrón existente de settings administrables.

## Risks / Trade-offs

| Riesgo | Mitigación |
|---|---|
| `Cache::has()` hace un GET en vez de EXISTS, ligeramente más costoso | Despreciable: `cleanOrphans` corre cada 30 min y procesa en chunks de 100. |
| Sesiones huérfanas reales (Redis reiniciado) no se limpian si `Cache::has` las encuentra | `Cache::has` retornaría false si la clave expiró o no existe — funciona correctamente para huérfanos reales. |
| Cambiar `global_session_lifetime` afecta a todos los usuarios | Es el comportamiento deseado: 24h de inactividad. Los usuarios con `session_lifetime_minutes` custom no se ven afectados. |