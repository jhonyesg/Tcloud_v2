# image-viewer-controls Specification

## Purpose
TBD - created by archiving change unify-viewer-controls. Update Purpose after archive.
## Requirements
### Requirement: Zoom en el visor de imágenes
El sistema SHALL permitir ampliar y reducir la imagen en el visor mediante botones y la rueda del ratón, tanto en el módulo de archivos internos como en el visor de shares públicos.

#### Scenario: Zoom con rueda del ratón en share público
- **WHEN** el usuario gira la rueda del ratón sobre la imagen en el visor de share público
- **THEN** la imagen se amplía (rueda hacia arriba) o se reduce (rueda hacia abajo) en pasos del 20%, con límite mínimo de 0.2x y máximo de 5x

#### Scenario: Zoom con botones en share público
- **WHEN** el usuario hace click en el botón "+" o "-" en la barra de controles del visor de share público
- **THEN** la imagen se amplía o reduce en un paso del 25%

#### Scenario: Reset de zoom en share público
- **WHEN** el usuario hace click en el botón de reset en el visor de share público
- **THEN** la imagen vuelve a escala 1:1, rotación 0° y posición centrada

### Requirement: Rotación en el visor de imágenes
El sistema SHALL permitir rotar la imagen en pasos de 90° en ambas direcciones, tanto en el módulo interno como en shares públicos.

#### Scenario: Rotar imagen en share público
- **WHEN** el usuario hace click en el botón rotar izquierda o derecha en el visor de share público
- **THEN** la imagen gira 90° en el sentido correspondiente

### Requirement: Pan de imagen ampliada en share público
El sistema SHALL permitir desplazar la imagen con el ratón cuando está ampliada en el visor de share público.

#### Scenario: Arrastre de imagen ampliada en share público
- **WHEN** la imagen está ampliada (escala > 1) en el share público y el usuario arrastra
- **THEN** la imagen se desplaza siguiendo el movimiento del cursor

### Requirement: Estado inicial al cambiar de archivo en share público
El sistema SHALL resetear zoom, rotación y pan al navegar entre archivos en el share público.

#### Scenario: Cambio de archivo resetea transformaciones
- **WHEN** el usuario hace click en otro archivo en la sidebar del share público
- **THEN** zoom vuelve a 1×, rotación a 0° y posición a centrada antes de mostrar el nuevo archivo

### Requirement: Video precarga al abrirse en share público
El sistema SHALL iniciar la precarga del video al abrirlo en el share público, igual que en el visor interno.

#### Scenario: Video comienza a cargar al seleccionarse
- **WHEN** el usuario selecciona un archivo de video en el share público
- **THEN** el video comienza a precargar inmediatamente sin necesidad de hacer click en play

