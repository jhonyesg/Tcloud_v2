## ADDED Requirements

### Requirement: Estado de conexión Redis
El sistema SHALL proveer la ruta `GET /admin/redis` (protegida por `['auth', 'admin']`) con una vista que muestra el estado de la conexión a Redis en tiempo real (al cargar la página y con botón de refrescar).

#### Scenario: Redis conectado
- **WHEN** el admin accede a `/admin/redis` y Redis está disponible
- **THEN** se muestra un indicador verde "Conectado" junto con la versión de Redis y el uptime

#### Scenario: Redis no disponible
- **WHEN** el admin accede a `/admin/redis` y Redis no responde
- **THEN** se muestra un indicador rojo "Error de conexión" con el mensaje de error
- **THEN** los demás paneles de estadísticas muestran "No disponible"

### Requirement: Estadísticas de memoria Redis
La vista SHALL mostrar: memoria usada por Redis (`used_memory_human`), memoria máxima configurada (`maxmemory_human`, "sin límite" si es 0), porcentaje de uso.

#### Scenario: Estadísticas visibles
- **WHEN** Redis está conectado
- **THEN** el panel muestra uso de memoria con barra de progreso visual si hay `maxmemory` configurado

### Requirement: Conteo de sesiones activas en Redis vs DB
La vista SHALL mostrar el número de keys de sesión activas en Redis (keys con prefijo de sesión de Laravel) y el número de registros en `user_sessions` en BD, con indicador de desync si difieren.

#### Scenario: Conteos sincronizados
- **WHEN** Redis y DB tienen el mismo número de sesiones
- **THEN** se muestran ambos conteos con indicador verde "Sincronizados"

#### Scenario: Desync detectado
- **WHEN** Redis tiene más keys de sesión que registros en `user_sessions` (o viceversa)
- **THEN** se muestran ambos conteos con indicador amarillo "Desync detectado" y botón "Limpiar sesiones huérfanas"

### Requirement: Limpieza de sesiones expiradas y huérfanas
La vista SHALL proveer un botón "Limpiar sesiones expiradas" que ejecuta `DELETE FROM user_sessions WHERE expires_at < now()` y un botón "Limpiar sesiones huérfanas" que elimina registros de `user_sessions` cuyo `session_id` no existe como key en Redis (o viceversa).

#### Scenario: Limpieza de expiradas
- **WHEN** el admin hace clic en "Limpiar sesiones expiradas"
- **THEN** se eliminan todos los registros de `user_sessions` con `expires_at < now()`
- **THEN** la vista muestra el número de registros eliminados

#### Scenario: Limpieza de huérfanas
- **WHEN** el admin hace clic en "Limpiar sesiones huérfanas"
- **THEN** se eliminan registros de `user_sessions` sin key correspondiente en Redis
- **THEN** la vista muestra el número de registros eliminados

### Requirement: Información general de Redis
La vista SHALL mostrar: versión de Redis, uptime en formato legible, número de clientes conectados, total de comandos procesados.

#### Scenario: Info visible
- **WHEN** Redis está conectado
- **THEN** el admin ve una tarjeta con la información general del servidor Redis
