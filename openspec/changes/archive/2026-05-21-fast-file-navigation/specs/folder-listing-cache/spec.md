## Requirements

### Requirement: Servir listados desde cache Redis
El sistema SHALL intentar leer el listado de archivos de una carpeta desde Redis antes de consultar la BD. Solo si hay cache miss se ejecuta la query SQL.

#### Scenario: Cache hit en navegacion
- **GIVEN** una carpeta con listado previamente cacheado en Redis
- **WHEN** el usuario navega a esa carpeta (sin sync=1)
- **THEN** la respuesta se sirve desde Redis sin ejecutar query a la BD

#### Scenario: Cache miss en primera visita
- **GIVEN** una carpeta sin entrada en Redis
- **WHEN** el usuario navega a esa carpeta por primera vez
- **THEN** se ejecuta la query a BD, se escribe el resultado en Redis, y se responde al usuario

#### Scenario: sync=1 bypass del cache
- **WHEN** la peticion incluye `sync=1`
- **THEN** se ignora el cache, se ejecuta syncFolder(), y se invalida la entrada de cache de esa carpeta

### Requirement: TTL diferenciado por antiguedad de carpeta
El sistema SHALL asignar TTL segun si la carpeta pertenece al dia actual o a dias pasados.

#### Scenario: Carpeta del dia actual
- **GIVEN** una carpeta cuyo `file_modified_at` es del dia de hoy (same calendar date en la zona horaria del servidor)
- **WHEN** se escribe su listado en Redis
- **THEN** el TTL es de 300 segundos (5 minutos)

#### Scenario: Carpeta de dia pasado
- **GIVEN** una carpeta cuyo `file_modified_at` es anterior al dia de hoy
- **WHEN** se escribe su listado en Redis
- **THEN** el TTL es de 86400 segundos (24 horas)

#### Scenario: Carpeta raiz de storage (parent_id = null)
- **WHEN** se cachea el listado raiz de un storage
- **THEN** el TTL es de 60 segundos

### Requirement: Invalidacion de cache al detectar cambios en sync
El sistema SHALL eliminar la entrada de cache de una carpeta cuando `syncFolder()` persiste al menos un cambio en esa carpeta.

#### Scenario: Nuevo archivo detectado por sync
- **GIVEN** una carpeta con listado cacheado en Redis
- **WHEN** el cron ejecuta syncFolder() y detecta un archivo nuevo
- **THEN** el registro de cache de esa carpeta es eliminado
- **AND** la proxima navegacion a esa carpeta obtiene el listado fresco desde BD y lo re-cachea
