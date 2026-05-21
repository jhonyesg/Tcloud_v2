## Requirements

### Requirement: Skip de carpetas sin cambios en fullSync
El sistema SHALL comparar el `mtime` del directorio en filesystem contra el campo `file_modified_at` de la carpeta en BD antes de invocar `scandir()`. Si el mtime del filesystem no es mayor al valor guardado, la carpeta es omitida.

#### Scenario: Carpeta inmutable (dia pasado)
- **GIVEN** una carpeta con `file_modified_at` = hace 3 dias
- **WHEN** `fullSync()` la procesa
- **AND** `filemtime(path)` retorna un timestamp <= `file_modified_at`
- **THEN** no se invoca `scandir()` ni ninguna operacion de BD sobre esa carpeta

#### Scenario: Carpeta activa con cambios
- **GIVEN** una carpeta del dia actual con archivos nuevos
- **WHEN** `fullSync()` la procesa
- **AND** `filemtime(path)` retorna un timestamp > `file_modified_at`
- **THEN** se invoca `syncFolder()` normalmente y se actualiza `file_modified_at` con la hora actual

#### Scenario: Carpeta sin file_modified_at (primera vez)
- **GIVEN** una carpeta cuyo `file_modified_at` es NULL en BD
- **WHEN** `fullSync()` la procesa
- **THEN** se invoca `syncFolder()` normalmente (no puede determinarse si cambio)

#### Scenario: Carpeta raiz del storage siempre se procesa
- **GIVEN** un storage local con carpetas nuevas creadas en el root hoy
- **WHEN** `fullSync()` corre
- **THEN** la carpeta raiz (parent_id = null) siempre se escanea para detectar nuevas subcarpetas

### Requirement: Actualizar file_modified_at de carpeta tras sync exitoso con cambios
El sistema SHALL escribir `file_modified_at = now()` en el registro de la carpeta en BD cuando `syncFolder()` detecta y persiste al menos un cambio (INSERT o DELETE de hijo).

#### Scenario: Sync sin cambios no toca file_modified_at
- **GIVEN** una carpeta cuyo contenido en filesystem coincide exactamente con BD
- **WHEN** `syncFolder()` corre sobre esa carpeta
- **THEN** el campo `file_modified_at` de la carpeta NO es modificado

#### Scenario: Sync con archivos nuevos actualiza file_modified_at
- **GIVEN** una carpeta con 3 archivos nuevos en filesystem no presentes en BD
- **WHEN** `syncFolder()` corre
- **THEN** los 3 archivos son insertados en BD
- **AND** `file_modified_at` de la carpeta es actualizado a `now()`
