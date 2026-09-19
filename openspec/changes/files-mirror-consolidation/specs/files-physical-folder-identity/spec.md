## Purpose

Define el modelo de identidad física de folder/file que permite resolver cross-storage de forma determinística, de modo que cada path físico tenga UNA fila canónica en `files` y las mirror rows en storages padre/hijo apunten a ella vía `merged_into_id`.

## ADDED Requirements

### Requirement: Cada folder físico tiene una identidad canónica única

El sistema SHALL identificar la identidad física de un folder/file como `physicalPathNormalized = lower(rtrim(base_path_snapshot || '/' || path))`. Dos folders/files con la misma identidad física representan el mismo directorio/archivo en disco. La fila canónica es la de `storage_provider_id` más específico (menor `storage_providers.parent_storage_id` chain) o, en empate, el storage con menor `id`.

#### Scenario: Folder con identidad física duplicada en dos storages
- **WHEN** dos filas de `files` tienen `is_folder=true`, mismo `name`, mismo `path` relativo, pero distinto `storage_provider_id` con `base_path_snapshot` que se complementan (ej. storage 37 base=`/Prensa`, storage 44 base=`/Prensa/Portafolio`; paths `Portafolio/20260918` vs `20260918`)
- **THEN** el sistema las reconoce como mirror rows de la misma identidad física
- **AND** una es marcada como canónica (`merged_into_id = NULL`) y la otra como mirror (`merged_into_id = canónica.id`)

#### Scenario: Folder único sin mirror
- **WHEN** un folder existe solo en un storage, sin identidad física duplicada
- **THEN** la fila queda con `merged_into_id = NULL` (canónica) sin necesidad de acción

#### Scenario: Files con misma identidad física
- **WHEN** un file existe físicamente en `/Prensa/Portafolio/Portafolio_20260918.pdf` (storage 44 base=`/Prensa/Portafolio`) y un sync anterior creó una mirror row en storage 37 base=`/Prensa` con path `Portafolio/Portafolio_20260918.pdf`
- **THEN** el file canónico vive en storage 44 y la mirror row apunta a él vía `merged_into_id`
- **AND** queries sobre storage 37 devuelven la mirror row (con `merged_into_id` apuntando al canónico)

### Requirement: `FilePhysicalIdentity::canonicalFor(File $file): File` resuelve el canónico

El sistema SHALL exponer un helper estático `FilePhysicalIdentity::canonicalFor(File $file): File` que retorna la fila canónica de un folder/file. Para canónicos (`merged_into_id IS NULL`), retorna el mismo row. Para mirrors, navega `merged_into_id` recursivamente hasta el canónico. Si la cadena tiene ciclos, retorna el primer row.

#### Scenario: Resolver canónico de un mirror row
- **WHEN** se invoca `canonicalFor($mirrorRow)` donde `$mirrorRow->merged_into_id = 123`
- **THEN** retorna `File::find(123)` (la fila canónica)

#### Scenario: Resolver canónico de un canónico
- **WHEN** se invoca `canonicalFor($canonical)` donde `$canonical->merged_into_id IS NULL`
- **THEN** retorna `$canonical` (el mismo row)

#### Scenario: Cadena de mirrors con ciclo (defensivo)
- **WHEN** se invoca `canonicalFor($mirror)` y la cadena `merged_into_id` forma un ciclo (A→B→A)
- **THEN** retorna el primer row visitado para evitar loop infinito

### Requirement: `StorageSyncService::syncFolder` crea mirror rows para folders Y files

El sistema SHALL, al sincronizar un folder en un storage, verificar si existe una fila canónica en otro storage con la misma `physicalPathNormalized`. Si existe, crear una mirror row (`merged_into_id = canónica.id`); si no, crear la fila como canónica. Esta verificación aplica a **folders Y files** dentro del folder procesado.

#### Scenario: Sync de folder en sub-storage sin mirror existente
- **WHEN** el sync procesa `/Prensa/Portafolio/20260918/imagenes/01.png` en storage 44
- **AND** no existe mirror row en storage 37 con `physicalPathNormalized = '/prensa/portafolio/20260918/imagenes/01.png'`
- **THEN** se crea la fila en storage 44 como canónica (`merged_into_id = NULL`)

#### Scenario: Sync de folder con mirror existente en storage padre
- **WHEN** el sync procesa `/Prensa/Portafolio/20260918/imagenes/01.png` en storage 44
- **AND** ya existe una fila en storage 37 con `physicalPathNormalized` idéntica
- **THEN** el sistema marca storage 37 como mirror (storage 37 ya tiene la fila) y verifica que el canónico es storage 44 (más específico)

#### Scenario: Sync idempotente
- **WHEN** el sync se ejecuta dos veces para el mismo folder
- **THEN** la segunda ejecución NO crea filas duplicadas (FileRegistry::ensure() las hace idempotentes por `(storage_id, path)`)
- **AND** el `merged_into_id` del mirror row se mantiene estable entre corridas

### Requirement: `PublicShareController` resuelve canónico antes de servir

El sistema SHALL, en `PublicShareController::show` y `showFolder`, invocar `FilePhysicalIdentity::canonicalFor()` sobre el file/folder solicitado. Si es un mirror row, servir el contenido del canónico. Esto garantiza que un share sobre `200_Diarios/Portafolio/20260918/imagenes/01.png` funcione aunque el archivo físico viva en `206 Portafolio/20260918/imagenes/01.png`.

#### Scenario: Share sobre mirror row descarga el file correcto
- **WHEN** un usuario accede a un share cuyo `file_id` apunta a un mirror row
- **THEN** `isDescendantOf()` usa `canonicalFor()` para verificar la cadena y el download sirve el contenido del canónico

#### Scenario: Listing de folder mirror muestra children del canónico
- **WHEN** un usuario navega un folder que es mirror row
- **THEN** el listing muestra los children del canónico (no los children del mirror, que pueden estar vacíos)

### Requirement: Comando `files:repair-folder-mirrors --apply` repara mirrors existentes

El sistema SHALL exponer un comando artisan `files:repair-folder-mirrors [--dry-run] [--apply]` que escanea BD vs filesystem y crea mirror rows faltantes para **files** (no solo folders). Tiempo estimado: 5-15 min para ~500k archivos con `session_replication_role = replica`.

#### Scenario: Dry-run reporta archivos sin mirror
- **WHEN** se ejecuta `--dry-run`
- **THEN** el comando cuenta archivos canónicos en sub-storages que NO tienen mirror row en storage padre y los lista en una tabla
- **AND** no muta nada

#### Scenario: Apply crea mirror rows
- **WHEN** se ejecuta `--apply`
- **THEN** el comando crea mirror rows en storage padre para cada archivo detectado en dry-run
- **AND** marca cada mirror con `merged_into_id = <canónico.id>`
- **AND** preserva el `path` relativo del storage padre (ej. `Portafolio/20260918/imagenes/01.png` para storage 37 base=`/Prensa`)

#### Scenario: Idempotencia del comando
- **WHEN** se ejecuta `--apply` dos veces consecutivas
- **THEN** la segunda ejecución crea 0 mirror rows nuevas (FileRegistry::ensure() ya las detectó)

### Requirement: Harness valida la consolidación

El sistema SHALL incluir `tests/harness_files_mirror_consolidation.php` con 6 secciones que verifican: detección de mirrors faltantes en BD actual, reparación idempotente, navegación cross-storage coherente, query de children via canonicalFor, listado desde storage padre incluye archivos, smoke test de share sobre mirror row.

#### Scenario: Harness detecta mirrors faltantes pre-fix
- **WHEN** se ejecuta el harness antes de la migración de mirror consolidation
- **THEN** la sección 1 reporta >0 mirror rows faltantes para `Portafolio/20260918/imagenes/*.png`

#### Scenario: Harness pasa post-fix
- **WHEN** se ejecuta después de aplicar `files:repair-folder-mirrors --apply` y la migración canónica
- **THEN** retorna exit 0 con todas las secciones PASS
