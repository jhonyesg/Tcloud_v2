# dashboard-tiered-cache Specification

## Purpose
Define cómo el dashboard obtiene su payload de métricas con una estrategia de cache por tiers de volatilidad, para reducir el tiempo de carga inicial sin sacrificar la frescura de los monitores operativos críticos.

## Requirements

### Requirement: Clasificación del payload por tiers de volatilidad

El sistema SHALL clasificar cada bloque de datos del dashboard en uno de tres tiers según su volatilidad: **frío** (cambia en horas o días), **tibio** (cambia en minutos) y **caliente** (cambia en segundos). El tier SHALL determinar si el bloque se sirve desde cache y por cuánto tiempo.

El tier caliente comprendec al menos: uso del RAM Disk `/mnt/cliptemp`, uso del SHM `/dev/shm`, sesiones activas y jobs en background. El tier frío comprende al menos: `total_users`, `total_storages`, `total_files`, `total_shares`, `active_shares`, `storage_used` y los agregados admin del editor de medios. El tier tibio comprende al menos el resumen de cobertura de Mis Avisos.

#### Scenario: Monitor crítico se mantiene en vivo
- **WHEN** el admin recarga `/dashboard` dos veces en un intervalo menor al TTL del tier frío y el uso del RAM Disk o SHM cambió entre ambas cargas
- **THEN** la segunda carga refleja el valor nuevo del monitor (no sirve un valor cacheado para los bloques del tier caliente)

#### Scenario: Dato frío se sirve desde cache
- **WHEN** el admin recarga `/dashboard` dentro del TTL del tier frío y `storage_used` no ha cambiado de forma invalidante
- **THEN** la carga no ejecuta el agregado `SUM(size)` sobre la tabla de archivos y responde con el valor cacheado

### Requirement: Cache anti-stampede para el tier frío

El sistema SHALL evitar que múltiples cargas concurrentes recalculen un bloque del tier frío de forma simultánea cuando su entrada de cache expira. Al expirar, el sistema SHALL servir el valor previo (stale) mientras un único proceso recalcula el valor nuevo en background.

#### Scenario: Expiración con cargas concurrentes
- **WHEN** la entrada de cache del tier frío expira y dos o más admins cargan `/dashboard` de forma concurrente
- **THEN** a lo sumo un proceso recalcula el bloque y las demás cargas reciben el valor previo sin bloquearse

#### Scenario: Primera carga sin cache previo
- **WHEN** no existe entrada de cache previa para un bloque del tier frío (arranque en frío o cache limpio)
- **THEN** la carga calcula el valor de forma síncrona y lo deja disponible para las siguientes cargas

### Requirement: Invalidación inmediata por epoch en el tier tibio

El sistema SHALL invalidar el bloque tibio de cobertura cuando ocurra una mutación sobre los watermarks, sin esperar al TTL, reutilizando el epoch de cobertura existente en la clave de cache.

#### Scenario: Mutación de watermarks invalida cobertura del dashboard
- **WHEN** se ejecuta un rewind, reconcile o ensureFor que incrementa el epoch de cobertura y el admin recarga `/dashboard`
- **THEN** el bloque de cobertura del dashboard refleja el estado posterior a la mutación en esa misma carga

### Requirement: Metadata de frescura del payload

El sistema SHALL exponer, junto al payload del dashboard, la marca temporal de generación de los datos cacheados y una señal de que fueron servidos como stale. Esta metadata SHALL permitir a la vista indicar al operador la antigüedad de los datos fríos.

#### Scenario: Indicador de frescura visible
- **WHEN** el admin carga `/dashboard` con datos del tier frío servidos desde cache
- **THEN** la vista muestra la antigüedad del dato (por ejemplo "actualizado hace X") en el bloque correspondiente

#### Scenario: Payload incluye marca de generación
- **WHEN** el sistema arma el payload del dashboard
- **THEN** el payload incluye una marca temporal de generación y una señal booleana de staleness para los bloques cacheados

### Requirement: Invalidación manual operativa

El sistema SHALL ofrecer un comando de consola para limpiar el cache del dashboard de forma manual, sin afectar las entradas de cache del módulo de cobertura administradas por el reconciler.

#### Scenario: Operador limpia caché del dashboard
- **WHEN** el operador ejecuta el comando de limpieza de cache del dashboard
- **THEN** las entradas del tier frío y tibio del dashboard quedan invalidadas y la siguiente carga recalcula los valores

### Requirement: Rendimiento de carga con cache tibio

El sistema SHALL reducir el tiempo de carga del dashboard admin al servir el tier frío desde cache. La contribución de `storage_used` y los agregados del editor de medios al tiempo de respuesta NO SHALL incluir el escaneo completo de la tabla de archivos en cada carga cuando exista una entrada de cache válida.

#### Scenario: Carga caliente del dashboard
- **WHEN** el admin carga `/dashboard` con todas las entradas de cache vigentes
- **THEN** el sistema NO ejecuta el agregado `SUM(size)` ni el conteo completo de archivos, y el tiempo de carga es sustancialmente menor que la primera carga en frío

### Requirement: Independencia del cache respecto a la privacidad del cliente

El sistema SHALL mantener el cache del dashboard limitado a datos agregados globales o por-contexto de admin. Los datos del dashboard de cliente SHALL NOT servirse desde una entrada de cache compartida con otro usuario cuando el valor dependa de un identificador de usuario.

#### Scenario: Cliente no hereda datos cacheados de otro usuario
- **WHEN** dos clientes distintos cargan `/dashboard` y un bloque del cliente depende de datos propios del usuario
- **THEN** cada cliente recibe únicamente sus propios valores, sin compartir una entrada de cache que pueda filtrar datos entre usuarios
