# files-mis-archivos-sub-storage-listing Specification

## Purpose

Permite que el listado de una carpeta en Mis Archivos muestre todos los archivos físicos de esa ruta aunque la propiedad esté repartida entre un storage padre y uno o más sub-storages cuya `base_path` sea prefijo de la carpeta navegada.

## Requirements

### Requirement: Listado cross-storage en Mis Archivos

Cuando el usuario navega a una carpeta `(storage_id, parent_id)` desde Mis Archivos, el sistema SHALL incluir en el listado los archivos cuyo `parent_id` esté registrado en cualquier storage cuya `base_path` sea prefijo del `path` absoluto de esa carpeta. El listado SHALL deduplicar por nombre prefiriendo el storage con `base_path` más largo (= sub-storage más específico).

#### Scenario: Carpeta del storage padre dentro de un sub-storage con archivos propios

- **WHEN** el usuario navega `00 Discos / Disco_B / television / Canal_Rcn / 16092026` y el sub-storage `02 RCN Tv` (cuyo `base_path` es prefijo de esa carpeta) tiene 49 archivos registrados en su `16092026` mientras `00 Discos` solo tiene los 32 archivos antiguos
- **THEN** Mis Archios MUST mostrar los 49 archivos únicos
- **AND** NO MUST mostrar duplicados del mismo nombre físico

#### Scenario: Carpeta sin sub-storage

- **WHEN** el usuario navega una carpeta cuyo `path` absoluto NO es prefijo de ningún otro `base_path` de storage
- **THEN** el listado MUST incluir únicamente los archivos del storage activo
- **AND** el comportamiento MUST ser idéntico al de antes de este change

#### Scenario: Root del storage padre

- **WHEN** el usuario navega el root de un storage sin seleccionar carpeta
- **THEN** el listado MUST incluir únicamente los archivos con `parent_id IS NULL` del storage activo

### Requirement: Sync desde storage padre sigue delegando al sub-storage

Cuando el usuario pulsa «Actualizar» desde una carpeta del storage padre cuya ruta cae dentro de un sub-storage, el sync SHALL seguir creando los archivos nuevos en el sub-storage (no duplicarlos en el padre), pero colgando del folder correspondiente del sub-storage (parent_id resuelto por path relativo), no de la raíz.

#### Scenario: Archivo nuevo delegado al folder correcto del sub-storage

- **WHEN** el sync crea un archivo nuevo en disco y delega a un sub-storage porque su `base_path` es prefijo
- **THEN** el archivo MUST crearse con `parent_id` igual al id del folder de misma ruta dentro del sub-storage
- **AND** si ese folder no existe en el sub-storage, MUST crearse en la raíz (parent_id NULL) preservando el comportamiento legacy

#### Scenario: Cache del sub-storage se invalida tras delegación

- **WHEN** un sync desde el storage padre delega archivos al sub-storage
- **THEN** el controller MUST invalidar también el cache de listing del folder correspondiente en el sub-storage, no solo del folder del padre