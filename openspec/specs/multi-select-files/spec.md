# multi-select-files Specification

## Purpose
TBD - created by archiving change descarga-multiple-archivos. Update Purpose after archive.
## Requirements
### Requirement: Checkbox de selección por archivo
El sistema SHALL mostrar un checkbox en cada tarjeta de archivo/carpeta que permita añadirlo a la selección múltiple sin afectar la navegación ni el visor.

#### Scenario: Hover sobre archivo en vista grid
- **WHEN** el usuario pasa el cursor sobre un archivo en vista grid
- **THEN** aparece un checkbox en la esquina superior izquierda de la tarjeta

#### Scenario: Click en checkbox
- **WHEN** el usuario hace click en el checkbox de un archivo no seleccionado
- **THEN** el archivo se añade a `selectedFiles` y el checkbox aparece marcado con fondo de color de selección

#### Scenario: Deseleccionar con checkbox
- **WHEN** el usuario hace click en el checkbox de un archivo ya seleccionado
- **THEN** el archivo se elimina de `selectedFiles` y el checkbox queda desmarcado

#### Scenario: Checkbox siempre visible en vista lista
- **WHEN** el usuario está en modo vista lista
- **THEN** el checkbox es visible en todo momento (sin necesidad de hover) en la primera columna de cada fila

### Requirement: Ctrl+Click para selección acumulativa
El sistema SHALL permitir añadir archivos a la selección usando Ctrl+Click sobre cualquier parte de la tarjeta (no solo el checkbox).

#### Scenario: Ctrl+Click en archivo no seleccionado
- **WHEN** el usuario hace Ctrl+Click sobre una tarjeta de archivo
- **THEN** el archivo se añade a `selectedFiles` sin limpiar la selección previa

#### Scenario: Ctrl+Click en archivo ya seleccionado
- **WHEN** el usuario hace Ctrl+Click sobre un archivo que ya está en `selectedFiles`
- **THEN** el archivo se elimina de la selección (toggle)

### Requirement: Click en área vacía limpia la selección
El sistema SHALL limpiar `selectedFiles` cuando el usuario hace click en el fondo del área de archivos (fuera de cualquier tarjeta).

#### Scenario: Click en fondo del panel
- **WHEN** el usuario hace click en la zona vacía del panel de archivos con elementos seleccionados
- **THEN** `selectedFiles` queda vacío y todos los checkboxes se desmarcan

### Requirement: Seleccionar todo en vista lista
El sistema SHALL incluir un checkbox en el encabezado de la columna de selección de la vista lista que permita seleccionar/deseleccionar todos los archivos visibles.

#### Scenario: Click en checkbox de cabecera — ninguno seleccionado
- **WHEN** ningún archivo está seleccionado y el usuario hace click en el checkbox de cabecera
- **THEN** todos los archivos visibles se añaden a `selectedFiles`

#### Scenario: Click en checkbox de cabecera — todos seleccionados
- **WHEN** todos los archivos visibles están seleccionados y el usuario hace click en el checkbox de cabecera
- **THEN** `selectedFiles` queda vacío

### Requirement: La selección no interfiere con la navegación
El sistema SHALL mantener el comportamiento de click normal (navegar a carpeta o abrir visor) cuando el usuario hace click fuera del área del checkbox y sin Ctrl.

#### Scenario: Click en nombre/icono de archivo
- **WHEN** el usuario hace click en el nombre o icono de un archivo (sin Ctrl)
- **THEN** se abre el visor del archivo; `selectedFiles` no cambia

#### Scenario: Click en nombre/icono de carpeta
- **WHEN** el usuario hace click en el nombre o icono de una carpeta (sin Ctrl)
- **THEN** el sistema navega dentro de la carpeta; `selectedFiles` se limpia

