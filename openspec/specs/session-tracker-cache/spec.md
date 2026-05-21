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
