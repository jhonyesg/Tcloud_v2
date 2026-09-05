## Purpose

Define el contrato del middleware `SessionTracker`: cómo cachea la validación de sesiones en Redis para evitar queries DB en cada request, cómo invalida esa caché al matar sesiones, cómo renueva el `expires_at` con sliding timeout respetando el lifetime por usuario, y cómo bloquea el paso cuando la sesión es inválida o el usuario no está activo.

## Requirements

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

### Requirement: cleanOrphans busca sesiones en la conexión `session`
El método `SessionService::cleanOrphans()` SHALL verificar la existencia de sesiones en Redis usando el helper `sessionExistsInRedis()`, que consulta la conexión `session` configurada en `config/database.php` (no la conexión `cache` ni la `default`). Esto evita el falso negativo universal que producía el bug original (cleanOrphans borrando 100% de filas cada 30 min).

#### Scenario: Sesión activa no se borra por cleanOrphans
- **WHEN** `cleanOrphans()` procesa una sesión que está activa en Redis (guardada como `{redis_prefix}{cache_prefix}{session_id}` en la conexión `session`)
- **THEN** `sessionExistsInRedis()` MUST retornar `true`
- **AND** el registro en `user_sessions` MUST NOT ser borrado

#### Scenario: Sesión huérfana real sí se borra
- **WHEN** `cleanOrphans()` procesa una sesión cuya clave en Redis ya expiró o no existe en la conexión `session`
- **THEN** `sessionExistsInRedis()` MUST retornar `false`
- **AND** el registro en `user_sessions` MUST ser borrado (salvo que el guardarraíl de ratio máximo aborte la corrida, ver `session-cleanup-safety`)

#### Scenario: Redis cae y la conexión `session` lanza excepción
- **WHEN** la conexión `session` lanza una excepción (Redis inalcanzable)
- **THEN** el sistema MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)

### Requirement: global_session_lifetime alineado con SESSION_LIFETIME
El valor `global_session_lifetime` en `system_settings` SHALL ser 1440 minutos (24 horas), alineado con `SESSION_LIFETIME=1440` en `.env`, para que el lifetime efectivo de sesión sea 24h de inactividad, no 8h.

#### Scenario: Usuario sin override de lifetime obtiene 24h
- **WHEN** un usuario sin `session_lifetime_minutes` custom tiene una sesión activa
- **THEN** `SessionService::getEffectiveLifetimeMinutes()` MUST retornar 1440
- **AND** la sesión MUST expirar solo tras 24h de inactividad

### Requirement: Verificación de existencia de sesión centralizada en helpers
El `SessionService` SHALL verificar la existencia de sesiones en Redis usando exclusivamente la conexión `session` configurada en `config/database.php`. El prefijo aplicado SHALL ser `{database.redis.options.prefix}{cache.prefix}{session_id}` (ej. `tcloud_tcloud_cache_{session_id}`). La construcción de clave y selección de conexión SHALL estar centralizada en los helpers privados `sessionRedisKey()` y `sessionRedisConnection()` para eliminar la posibilidad de divergencia entre callers. Ver capability `session-redis-connection`.

#### Scenario: countActiveSessions cuenta sesiones correctamente
- **WHEN** `countActiveSessions()` evalúa las sesiones de un usuario
- **THEN** cada sesión MUST ser verificada con `sessionExistsInRedis()` en la conexión `session`
- **AND** solo las sesiones con clave existente en Redis MUST ser contadas

#### Scenario: killSession elimina la sesión del Redis correcto
- **WHEN** `killSession()` elimina una sesión
- **THEN** la clave `{redis_prefix}{cache_prefix}{session_id}` MUST ser eliminada de la conexión `session`
- **AND** la clave de caché `session_valid:{session_id}` MUST ser eliminada del cache store
- **AND** el registro en `user_sessions` MUST ser eliminado

#### Scenario: Redis cae y la verificación lanza excepción
- **WHEN** la conexión `session` lanza una excepción (Redis inalcanzable)
- **THEN** `cleanOrphans()` MUST capturar la excepción y NO borrar el registro (conservador: si no se puede verificar, no borrar)
- **AND** `countActiveSessions()` MUST capturar la excepción y contar la sesión como activa (conservador)
