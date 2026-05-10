## ADDED Requirements

### Requirement: Filtro rápido por tipo de storage
La tabla SHALL incluir un control de filtro que permita mostrar solo storages de un tipo específico (local, s3) o todos los tipos.

#### Scenario: Filtro "Todos" muestra todos los tipos
- **WHEN** el filtro de tipo está en la opción "Todos"
- **THEN** la tabla muestra storages de cualquier tipo sin restricción por tipo

#### Scenario: Filtro por tipo específico
- **WHEN** el usuario selecciona un tipo específico (ej. "S3") en el filtro de tipo
- **THEN** solo se muestran los storages cuyo campo `type` coincide con el tipo seleccionado

#### Scenario: Filtro de tipo combinado con búsqueda
- **WHEN** hay una búsqueda de texto activa y el usuario selecciona un tipo
- **THEN** se muestran solo los storages que cumplen ambas condiciones (tipo Y texto de búsqueda)

### Requirement: Filtro rápido por estado de storage
La tabla SHALL incluir un control de filtro que permita mostrar solo storages activos, solo inactivos, o todos.

#### Scenario: Filtro "Todos" no restringe por estado
- **WHEN** el filtro de estado está en "Todos"
- **THEN** la tabla muestra storages activos e inactivos

#### Scenario: Filtro por estado activo
- **WHEN** el usuario selecciona "Activo" en el filtro de estado
- **THEN** solo se muestran los storages con estado activo

#### Scenario: Filtro por estado inactivo
- **WHEN** el usuario selecciona "Inactivo" en el filtro de estado
- **THEN** solo se muestran los storages con estado inactivo

### Requirement: Botón para limpiar todos los filtros
La interfaz SHALL incluir un control para restablecer todos los filtros y la búsqueda a su estado inicial.

#### Scenario: Limpiar filtros restablece la vista completa
- **WHEN** el usuario hace clic en "Limpiar filtros" (o equivalente)
- **THEN** el cuadro de búsqueda se vacía, los filtros de tipo y estado vuelven a "Todos", y se muestra la primera página con todos los registros
