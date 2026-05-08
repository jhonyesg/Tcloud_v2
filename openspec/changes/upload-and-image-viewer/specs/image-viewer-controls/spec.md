## ADDED Requirements

### Requirement: Zoom en el visor de imágenes
El sistema SHALL permitir ampliar y reducir la imagen en el visor mediante botones y la rueda del ratón.

#### Scenario: Zoom con rueda del ratón
- **WHEN** el usuario gira la rueda del ratón sobre la imagen en el visor
- **THEN** la imagen se amplía (rueda hacia arriba) o se reduce (rueda hacia abajo) en pasos del 20%, con límite mínimo de 0.2x y máximo de 5x

#### Scenario: Zoom con botones
- **WHEN** el usuario hace click en el botón "+" o "-" en la barra de controles del visor
- **THEN** la imagen se amplía o reduce en un paso del 25%

#### Scenario: Reset de zoom
- **WHEN** el usuario hace click en el botón de reset o presiona la tecla "0"
- **THEN** la imagen vuelve a escala 1:1, rotación 0° y posición centrada

### Requirement: Rotación en el visor de imágenes
El sistema SHALL permitir rotar la imagen en pasos de 90° en ambas direcciones.

#### Scenario: Rotar a la izquierda
- **WHEN** el usuario hace click en el botón de rotar izquierda
- **THEN** la imagen gira 90° en sentido antihorario

#### Scenario: Rotar a la derecha
- **WHEN** el usuario hace click en el botón de rotar derecha
- **THEN** la imagen gira 90° en sentido horario

#### Scenario: Rotaciones acumuladas
- **WHEN** el usuario rota la imagen múltiples veces
- **THEN** cada rotación se acumula sobre la anterior (e.g., dos rotaciones derecha = 180°)

### Requirement: Pan (desplazamiento) de imagen ampliada
El sistema SHALL permitir desplazar la imagen con el ratón cuando está ampliada (escala > 1).

#### Scenario: Arrastre de imagen ampliada
- **WHEN** la imagen está ampliada (escala > 1) y el usuario hace click y arrastra sobre ella
- **THEN** la imagen se desplaza siguiendo el movimiento del cursor

#### Scenario: Cursor indica arrastre disponible
- **WHEN** la imagen está ampliada
- **THEN** el cursor cambia a "grab" (mano abierta) al pasar sobre la imagen y a "grabbing" (mano cerrada) al arrastrar

### Requirement: Estado inicial del visor de imágenes
El sistema SHALL resetear todos los controles al abrir una nueva imagen.

#### Scenario: Apertura de nueva imagen
- **WHEN** el usuario abre una imagen en el visor
- **THEN** la imagen se muestra con escala 1, rotación 0° y sin desplazamiento, independientemente del estado de la imagen anterior
