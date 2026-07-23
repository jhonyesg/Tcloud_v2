## ADDED Requirements

### Requirement: Validación de sesión cacheada en Redis
El middleware `SessionTracker` SHALL cachear el resultado de la consulta a `user_sessions` en Redis con clave `session_valid:{session_id}` y TTL de 30 segundos, para evitar una query DB en cada request HTTP.

#### Scenario: Request con sesión válida en caché
- **WHEN** llega un request con una sesión que ya fue validada en los últimos 30 s
- **THEN** el middleware usa el resultado en caché sin consultar la base de datos

#### Scenario: Request con sesión no cacheada
- **WHEN** llega un request cuya sesión no está en caché (primera vez o TTL expirado)
- **THEN** el middleware consulta `user_sessions` en BD y guarda el resultado en Redis por 30 s

### Requirement: Invalidación inmediata de caché al matar sesión
Cuando `SessionService::killSession()` elimina una sesión, el sistema SHALL borrar inmediatamente la clave `session_valid:{session_id}` de Redis.

#### Scenario: Admin revoca sesión de usuario
- **WHEN** un administrador elimina la sesión de un usuario
- **THEN** la clave de caché correspondiente se elimina de Redis y el próximo request del usuario recibe 401 sin esperar el TTL

### Requirement: Caché solo para sesiones existentes
El sistema SHALL cachear únicamente la confirmación de sesión válida. Las sesiones inválidas (registro no encontrado o expirado) NO deben cachearse para garantizar que el redirect a login sea inmediato.

#### Scenario: Sesión inválida no se cachea
- **WHEN** `UserSession::where('session_id', ...)->first()` retorna null
- **THEN** no se escribe ninguna entrada en Redis y el usuario es redirigido a login

### Requirement: Memoización de storages del usuario por request
`User::hasStoragePermission()` SHALL memoizar el resultado de `$this->userStorages()->get()` en una propiedad del modelo durante el ciclo de vida del request, para evitar queries repetidas cuando se verifica permiso sobre múltiples archivos.

#### Scenario: Permisos consultados múltiples veces en un request
- **WHEN** un request consulta `hasStoragePermission` para el mismo usuario más de una vez
- **THEN** solo se ejecuta una query a `user_storages` por request, no una por llamada

### Requirement: Sliding session expiry renewal on activity
The `SessionTracker` middleware SHALL renew `expires_at` in `user_sessions` whenever it renews `last_activity_at` (i.e., at most once per 60 seconds per active session). The new `expires_at` MUST be calculated as `now() + session_lifetime` using `SessionService::getEffectiveLifetimeMinutes()` to respect per-user and global lifetime overrides.

#### Scenario: Active request after 60 seconds of last renewal
- **WHEN** an authenticated request arrives and `last_activity_at` was last updated more than 60 seconds ago
- **THEN** the middleware MUST update both `last_activity_at` and `expires_at` in a single `UPDATE` query
- **AND** `expires_at` MUST equal `now() + effectiveLifetimeMinutes`

#### Scenario: Active request within 60 seconds of last renewal
- **WHEN** an authenticated request arrives and `last_activity_at` was updated less than 60 seconds ago
- **THEN** the middleware MUST NOT update `expires_at` or `last_activity_at` (throttled)

#### Scenario: User with unlimited lifetime (lifetime=0)
- **WHEN** `SessionService::getEffectiveLifetimeMinutes()` returns 0 for a user
- **THEN** the middleware MUST NOT set `expires_at` (leave it as null) and the session MUST NOT expire by inactivity

### Requirement: Cache invalidation after expiry renewal
The `SessionTracker` middleware SHALL invalidate the `session_valid:{session_id}` cache key immediately after renewing `expires_at`, so that the next request re-validates against the database with the updated expiry.

#### Scenario: Expiry renewed, cache invalidated
- **WHEN** the middleware renews `expires_at` in `user_sessions`
- **THEN** the middleware MUST call `Cache::forget("session_valid:{session_id}")`
- **AND** the next request MUST re-query `user_sessions` to confirm the renewed `expires_at`

### Requirement: Session lifetime default of 24 hours
The system SHALL default `SESSION_LIFETIME` to 1440 minutes (24 hours) in `.env` and `.env.example`, so that the sliding inactivity window allows a full day of continuous use without forced re-login.

#### Scenario: Fresh deployment with default env
- **WHEN** a deployment uses the default `.env.example` configuration
- **THEN** `SESSION_LIFETIME` MUST be 1440
- **AND** sessions MUST expire only after 24 hours of inactivity, not 2 hours from login

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

### Requirement: Verificación de existencia de sesión en Redis con conexión y prefijo correctos
El `SessionService` SHALL verificar la existencia de sesiones en Redis usando `Redis::connection('default')` con el prefijo del cache store (`config('cache.prefix')`), produciendo la clave `{redis_prefix}{cache_prefix}{session_id}` (ej. `tcloud_tcloud_cache_{session_id}`). Esto se centraliza en un helper `sessionExistsInRedis()` para evitar discrepancias entre el cache store (DB 1) y la conexión de sesión (DB 0).

#### Scenario: cleanOrphans no borra sesiones activas
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (clave `tcloud_tcloud_cache_{session_id}` existe en DB 0)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: cleanOrphans sí borra sesiones huérfanas
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe en DB 0
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado

#### Scenario: countActiveSessions cuenta sesiones correctamente
- **WHEN** `countActiveSessions()` evalúa las sesiones de un usuario
- **THEN** cada sesión MUST ser verificada con `sessionExistsInRedis()` en DB 0
- **AND** solo las sesiones con clave existente en Redis MUST ser contadas

#### Scenario: killSession elimina la sesión del Redis correcto
- **WHEN** `killSession()` elimina una sesión
- **THEN** la clave `tcloud_tcloud_cache_{session_id}` MUST ser eliminada de DB 0 (conexión `default`)
- **AND** la clave de caché `session_valid:{session_id}` MUST ser eliminada del cache store

#### Scenario: Redis cae y la verificación lanza excepción
- **WHEN** `Redis::connection('default')` lanza una excepción (Redis inalcanzable)
- **THEN** `cleanOrphans()` MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)
- **AND** `countActiveSessions()` MUST capturar la excepción y contar la sesión como activa (conservador)
