## MODIFIED Requirements

### Requirement: Descarga sin navegación en shares públicos
El sistema SHALL descargar el archivo al pulsar el botón de descarga en cualquier share público sin navegar fuera de la página actual, independientemente del tipo de archivo o nivel de permisos.

(Previamente: el share podía crearse sobre un mirror row y la descarga canónica el `file_id` antes de servir. Ahora: el `file_id` del share ES el row servido; no hay canonicalización previa.)

#### Scenario: Descarga desde lista de carpeta
- **WHEN** el usuario pulsa el botón de descarga en la vista de lista o grid de una carpeta compartida
- **THEN** el archivo se descarga al dispositivo sin que el navegador abandone la página del share

#### Scenario: Descarga desde vista de archivo único
- **WHEN** el usuario pulsa el botón "Descargar" en la vista de share de archivo único
- **THEN** el archivo se descarga con `Content-Disposition: attachment` sin abrir el archivo en la pestaña

#### Scenario: Comportamiento igual en lectura y escritura
- **WHEN** el share tiene permiso `read` o permiso `write`
- **THEN** el botón de descarga funciona de la misma manera en ambos casos

### Requirement: Share sobre folder requiere parent_id navegable
El sistema SHALL permitir crear un share sobre un folder solo si el `file_id` del folder es navegable desde la raíz del `storage_provider_id` del folder (walk de `parent_id`).

(Previamente: el sistema aceptaba shares sobre mirror rows con fallback `physicalPathNormalized`. Ahora: el share se crea sobre el row recibido; si el row es un mirror vacío, el share aparece vacío.)

#### Scenario: Share sobre folder canónico funciona
- **WHEN** el operador crea un share sobre `file_id = 7244491` (folder canónico en storage 5) que apunta a un folder con `storage_provider_id = 5`
- **THEN** el destinatario del share navega los children del folder y ve los archivos del storage 5

#### Scenario: Share sobre folder cross-storage queda fuera de scope
- **WHEN** el operador intenta crear un share sobre un folder que vive en storage 7 (sub-storage) pero la URL apunta a un mirror en storage 5 (parent)
- **THEN** el sistema detecta que el row no es navegable por `parent_id` walk desde el storage del destinatario
- **AND** rechaza la creación con HTTP 422 + mensaje "El folder seleccionado no es navegable desde tu storage. Selecciona el folder canónico del sub-storage"
