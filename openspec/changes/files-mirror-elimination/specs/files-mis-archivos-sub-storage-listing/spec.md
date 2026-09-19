## MODIFIED Requirements

### Requirement: Listado cross-storage en Mis Archivos

Cuando el usuario navega a una carpeta `(storage_id, parent_id)` desde Mis Archivos, el sistema SHALL incluir en el listado los archivos cuyo `parent_id` corresponda a cualquier fila de carpeta que represente **la misma identidad física** que la carpeta navegada — definida como `lower(rtrim(base_path || '/' || path))` —, incluyendo las filas registradas en sub-storages más específicos. El listado SHALL deduplicar por nombre prefiriendo el storage con `base_path` más largo (= sub-storage más específico).

#### Scenario: Carpeta del storage padre dentro de un sub-storage con archivos propios

- **WHEN** el usuario navega `00 Discos / Disco_B / television / Canal_Rcn / 16092026` y el sub-storage `02 RCN Tv` (cuyo `base_path` es prefijo de esa carpeta) tiene 49 archivos registrados en su `16092026` mientras `00 Discos` solo tiene los 32 archivos antiguos
- **THEN** Mis Archios MUST mostrar los 49 archivos únicos
- **AND** NO MUST mostrar duplicados del mismo nombre físico

#### Scenario: El contenido del sub-storage aparece al navegar desde el padre sin filas espejo

- **WHEN** el usuario navega `200_Diarios > Portafolio > 20260918 > imagenes` (storage 37) y la carpeta física correspondiente tiene su fila canónica únicamente en storage 44 (`206 Portafolio`)
- **THEN** el listado MUST devolver los 16 archivos PNG de esa carpeta
- **AND** el listado NO MUST devolver vacío
- **AND** NO MUST requerir que exista una fila espejo en storage 37

#### Scenario: Carpeta navegada que es la raíz de un sub-storage

- **WHEN** el usuario navega una carpeta del storage padre cuyo path absoluto es exactamente el `base_path` de un sub-storage
- **THEN** el listado MUST resolver el folder raíz de ese sub-storage (`parent_id IS NULL`) e incluir sus hijos
- **AND** el listado NO MUST fallar por no encontrar un folder hijo con path relativo vacío

#### Scenario: El cliente adopta el storage de la fila devuelta al navegar

- **WHEN** el listado devuelve una carpeta cuya `storage_provider_id` difiere del storage activo
- **AND** el usuario entra en esa carpeta
- **THEN** el cliente MUST adoptar el `storage_provider_id` de la fila como storage activo para el siguiente request
- **AND** el siguiente request NO MUST combinar el storage anterior con el `parent_id` de la fila nueva

#### Scenario: Carpeta sin sub-storage

- **WHEN** el usuario navega una carpeta cuyo `path` absoluto NO es prefijo de ningún otro `base_path` de storage
- **THEN** el listado MUST incluir únicamente los archivos del storage activo
- **AND** el comportamiento MUST ser idéntico al de antes de este change

#### Scenario: Root del storage padre

- **WHEN** el usuario navega el root de un storage sin seleccionar carpeta
- **THEN** el listado MUST incluir únicamente los archivos con `parent_id IS NULL` del storage activo
