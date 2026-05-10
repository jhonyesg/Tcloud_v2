## ADDED Requirements

### Requirement: Encabezados de columna con ordenamiento clicable
Los encabezados de las columnas ID, Nombre, Tipo, Archivos y Estado de la tabla de storages SHALL ser clicables para ordenar los registros de forma ascendente o descendente.

#### Scenario: Primer clic en un encabezado ordena ascendentemente
- **WHEN** el usuario hace clic en el encabezado de una columna que no está actualmente activa como criterio de ordenamiento
- **THEN** los registros de la tabla se ordenan por esa columna en orden ascendente (A→Z, 0→9)

#### Scenario: Segundo clic en el mismo encabezado invierte el orden
- **WHEN** el usuario hace clic en el encabezado de la columna que ya está activa como criterio de ordenamiento
- **THEN** el orden se invierte (de asc a desc, o de desc a asc)

#### Scenario: Indicador visual del ordenamiento activo
- **WHEN** una columna está activa como criterio de ordenamiento
- **THEN** el encabezado muestra una flecha (↑ para asc, ↓ para desc) junto al nombre de la columna
- **THEN** las columnas inactivas muestran un icono neutro o no muestran indicador de dirección

#### Scenario: Ordenamiento por defecto al cargar
- **WHEN** la página de storages carga por primera vez
- **THEN** la tabla se muestra ordenada por ID en orden ascendente

### Requirement: Ordenamiento estable con filtros activos
El ordenamiento SHALL respetar el subconjunto filtrado de registros y no el array completo.

#### Scenario: Ordenamiento aplicado sobre resultados filtrados
- **WHEN** hay filtros de búsqueda o tipo/estado activos y el usuario cambia el criterio de ordenamiento
- **THEN** el ordenamiento se aplica únicamente sobre los registros visibles tras el filtrado
