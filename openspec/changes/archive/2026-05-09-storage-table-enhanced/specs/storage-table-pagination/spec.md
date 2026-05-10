## ADDED Requirements

### Requirement: Paginación del lado del cliente
La tabla de storages SHALL dividir los registros visibles en páginas para limitar el número de filas renderizadas simultáneamente.

#### Scenario: Número de registros por página por defecto
- **WHEN** la página de storages carga por primera vez
- **THEN** se muestran máximo 25 registros por página

#### Scenario: Selector de registros por página
- **WHEN** el usuario cambia el selector de registros por página (opciones: 10, 25, 50)
- **THEN** la tabla se actualiza para mostrar la cantidad seleccionada de registros por página y se vuelve a la página 1

#### Scenario: Navegación entre páginas
- **WHEN** hay más registros que el límite por página
- **THEN** se muestran controles de paginación (anterior, número de página, siguiente) debajo de la tabla

#### Scenario: Deshabilitar controles en límites
- **WHEN** el usuario está en la primera página
- **THEN** el botón "Anterior" está deshabilitado o no es clicable
- **WHEN** el usuario está en la última página
- **THEN** el botón "Siguiente" está deshabilitado o no es clicable

#### Scenario: Paginación se reinicia al cambiar filtros
- **WHEN** el usuario cambia la búsqueda, el filtro de tipo o el filtro de estado
- **THEN** la paginación vuelve automáticamente a la página 1

### Requirement: Información de paginación visible
La interfaz SHALL mostrar información contextual sobre la posición de paginación actual.

#### Scenario: Rango de registros visibles
- **WHEN** la paginación está activa
- **THEN** se muestra un texto como "Mostrando 26-50 de 80 storages" indicando el rango actual y el total filtrado

#### Scenario: Sin paginación cuando los registros caben en una página
- **WHEN** el total de registros filtrados es menor o igual al límite por página
- **THEN** los controles de paginación se ocultan y no se muestran números de página
