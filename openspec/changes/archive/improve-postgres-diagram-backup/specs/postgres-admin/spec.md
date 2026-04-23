## MODIFIED Requirements

### Requirement: Tablas arrastrables en diagrama
Las tablas del diagrama DEBERAN poder ser arrastradas con el mouse para reorganizarlas.

#### Scenario: Arrastrar tabla
- **WHEN** usuario hace click y arrastra una tabla
- **THEN** la tabla SHALL seguir el cursor mientras se arrastra
- **AND** SHALL mostrar feedback visual de cursor (grab/grabbing)

#### Scenario: Soltar tabla en nueva posicion
- **WHEN** usuario suelta la tabla
- **THEN** la tabla SHALL permanecer en la nueva posicion
- **AND** SHALL guardar posicion en localStorage

### Requirement: Posiciones persistidas
Las posiciones de las tablas DEBERAN guardarse en localStorage y restaurarse al recargar.

#### Scenario: Guardar posicion
- **WHEN** usuario arrastra y suelta una tabla
- **THEN** SHALL guardar {tableName: {x, y}} en localStorage key 'postgres_diagram_positions'

#### Scenario: Restaurar posiciones
- **WHEN** se carga el diagrama
- **THEN** SHALL cargar posiciones de localStorage
- **AND** SHALL usar esas posiciones si existen

### Requirement: Feedback de backup con modal
El sistema DEBERA mostrar un modal cuando se inicia el backup.

#### Scenario: Iniciar backup local
- **WHEN** usuario hace clic en "Descargar Backup SQL"
- **THEN** SHALL mostrar modal "Generando backup..."
- **AND** SHALL cambiar a "Backup generado exitosamente" cuando termine
- **OR** SHALL mostrar "Error: {mensaje}" si falla
