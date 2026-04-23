## ADDED Requirements

### Requirement: Lineas FK siguen a las tablas arrastradas
Las lineas de relaciones FK DEBERAN actualizarse en tiempo real mientras se arrastra una tabla.

#### Scenario: Arrastrar tabla con FK
- **WHEN** usuario arrastra una tabla que tiene FK referencing otra tabla
- **THEN** las lineas FK SHALL actualizarse en cada movimiento del mouse
- **AND** SHALL mantener la conexion visual correcta con la tabla referenciada

### Requirement: Control de zoom
El diagrama DEBERA tener controles de zoom para facilitar la navegacion.

#### Scenario: Zoom in
- **WHEN** usuario hace clic en boton "+" o usa scroll del mouse hacia arriba
- **THEN** SHALL aplicar zoom in al diagrama (aumentar escala)

#### Scenario: Zoom out
- **WHEN** usuario hace clic en boton "-" o usa scroll del mouse hacia abajo
- **THEN** SHALL aplicar zoom out al diagrama (disminuir escala)

#### Scenario: Reset zoom
- **WHEN** usuario hace clic en boton "Reset"
- **THEN** SHALL restaurar zoom al 100%
