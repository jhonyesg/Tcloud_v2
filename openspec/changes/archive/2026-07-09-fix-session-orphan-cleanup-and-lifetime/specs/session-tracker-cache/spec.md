## ADDED Requirements

### Requirement: cleanOrphans busca sesiones con el prefijo correcto del cache store
El método `SessionService::cleanOrphans()` SHALL verificar la existencia de sesiones en Redis usando `Cache::has($session->session_id)` (que aplica el prefijo del cache store `tcloud_cache_`), no `Redis::exists()` (que solo aplica el prefijo de la conexión Redis `tcloud_`). Esto evita falsos negativos que borran sesiones válidas.

#### Scenario: Sesión activa no se borra por cleanOrphans
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (guardada como `tcloud_tcloud_cache_{session_id}`)
- **THEN** `Cache::has($session->session_id)` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: Sesión huérfana real sí se borra
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe
- **THEN** `Cache::has($session->session_id)` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado

#### Scenario: Redis cae y Cache::has lanza excepción
- **WHEN** `Cache::has()` lanza una excepción (Redis inalcanzable)
- **THEN** el sistema MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)

### Requirement: global_session_lifetime alineado con SESSION_LIFETIME
El valor `global_session_lifetime` en `system_settings` SHALL ser 1440 minutos (24 horas), alineado con `SESSION_LIFETIME=1440` en `.env`, para que el lifetime efectivo de sesión sea 24h de inactividad, no 8h.

#### Scenario: Usuario sin override de lifetime obtiene 24h
- **WHEN** un usuario sin `session_lifetime_minutes` custom tiene una sesión activa
- **THEN** `SessionService::getEffectiveLifetimeMinutes()` MUST retornar 1440
- **AND** la sesión MUST expirar solo tras 24h de inactividad