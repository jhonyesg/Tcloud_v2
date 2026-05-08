# share-file-download Specification

## Purpose
TBD - created by archiving change fix-share-download-behavior. Update Purpose after archive.
## Requirements
### Requirement: Descarga sin navegación en shares públicos
El sistema SHALL descargar el archivo al pulsar el botón de descarga en cualquier share público sin navegar fuera de la página actual, independientemente del tipo de archivo o nivel de permisos.

#### Scenario: Descarga desde lista de carpeta
- **WHEN** el usuario pulsa el botón de descarga en la vista de lista o grid de una carpeta compartida
- **THEN** el archivo se descarga al dispositivo sin que el navegador abandone la página del share

#### Scenario: Descarga desde vista de archivo único
- **WHEN** el usuario pulsa el botón "Descargar" en la vista de share de archivo único
- **THEN** el archivo se descarga con `Content-Disposition: attachment` sin abrir el archivo en la pestaña

#### Scenario: Comportamiento igual en lectura y escritura
- **WHEN** el share tiene permiso `read` o permiso `write`
- **THEN** el botón de descarga funciona de la misma manera en ambos casos

