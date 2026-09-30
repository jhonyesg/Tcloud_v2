## Purpose

Define el contrato del array `breadcrumbs` que el backend entrega al cliente de Mis Archivos en cada navegación de carpeta, de modo que la UI pueda renderizar un breadcrumb consistente sin duplicar el segmento de la carpeta actual.

## ADDED Requirements

### Requirement: El breadcrumb solo incluye ancestros

Cuando el sistema responde a una navegación de carpeta (`/files` AJAX con `parent_id` válido), el array `breadcrumbs` SHALL contener **únicamente los ancestros** del folder solicitado, ordenados desde el storage root hasta el padre inmediato. La carpeta actual (el folder solicitado) SHALL **NO** aparecer en el array.

#### Scenario: Navegación a una carpeta profunda
- **WHEN** el cliente solicita `/files?parent_id=8499102&storage_id=5` y la cadena en BD es `Disco_I > television > Telemedellin > 30092026`
- **THEN** el sistema responde `breadcrumbs: [{id:4806263,name:"Disco_I"}, {id:4810747,name:"television"}, {id:8451988,name:"Telemedellin"}]`
- **AND** el objeto con `id: 8499102,name:"30092026"` NO está presente en el array

#### Scenario: Navegación al root del storage
- **WHEN** el cliente solicita `/files?storage_id=5` sin `parent_id`
- **THEN** el sistema responde `breadcrumbs: []`

#### Scenario: Carpeta raíz directa del storage
- **WHEN** el cliente solicita `/files?parent_id=4806263&storage_id=5` y la carpeta es hija directa de root
- **THEN** el sistema responde `breadcrumbs: [{id:4806263,name:"Disco_I"}]` solo si ese folder es hijo de root, **NO** debe aparecer en el array

### Requirement: La carpeta actual se identifica por separado

La respuesta del sistema SHALL exponer `currentFolderName` (string o `null`) y `currentFolder` (id o `null`) en el payload del AJAX para que el cliente pueda renderizar la carpeta actual sin depender del array `breadcrumbs`.

#### Scenario: Payload completo incluye current folder
- **WHEN** el cliente navega a una carpeta válida
- **THEN** el payload contiene `currentFolder: 8499102` y `currentFolderName: "30092026"`
- **AND** estos campos son coherentes con el `parent_id` del request (round-trip verificable)

### Requirement: Coherencia con modo FS-first

Cuando el modo `FilesystemListingService` (FS-first) está activo para el storage solicitado, su array de breadcrumb SHALL respetar la misma regla: ancestros únicamente, sin la carpeta actual. El contrato es idéntico al modo BD-first.

#### Scenario: Misma regla en ambos modos
- **WHEN** el mismo folder se navega con FS-first activo (canary `storage_provider_id IN canary_ids`) y con BD-first (resto de storages)
- **THEN** ambos endpoints devuelven `breadcrumbs` con la misma longitud y los mismos `name`/`id` para cada ancestro
- **AND** ninguno de los dos incluye la carpeta actual en el array
