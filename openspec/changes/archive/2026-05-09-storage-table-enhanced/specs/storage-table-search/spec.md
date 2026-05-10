## ADDED Requirements

### Requirement: Cuadro de búsqueda global en tiempo real
La tabla de storages SHALL incluir un cuadro de texto de búsqueda que filtre los registros visibles en tiempo real conforme el usuario escribe, sin requerir presionar Enter ni botón de búsqueda.

#### Scenario: Búsqueda por nombre de storage
- **WHEN** el usuario escribe texto en el cuadro de búsqueda
- **THEN** solo se muestran los storages cuyo nombre contiene el texto ingresado (búsqueda insensible a mayúsculas/minúsculas)

#### Scenario: Búsqueda por tipo de storage
- **WHEN** el usuario escribe "s3" o "local" en el cuadro de búsqueda
- **THEN** solo se muestran los storages cuyo tipo coincide con el texto ingresado

#### Scenario: Búsqueda por estado
- **WHEN** el usuario escribe "activo" o "inactivo" en el cuadro de búsqueda
- **THEN** solo se muestran los storages cuyo estado visible coincide con el texto ingresado

#### Scenario: Sin resultados para la búsqueda
- **WHEN** el término de búsqueda no coincide con ningún registro
- **THEN** la tabla muestra un mensaje "No se encontraron storages" en lugar de filas vacías

#### Scenario: Limpiar búsqueda restaura todos los registros
- **WHEN** el usuario borra el texto del cuadro de búsqueda
- **THEN** la tabla vuelve a mostrar todos los storages aplicando solo los filtros de tipo/estado activos

### Requirement: Contador de resultados visibles
La interfaz SHALL mostrar cuántos registros se están visualizando del total disponible.

#### Scenario: Contador actualizado al buscar
- **WHEN** hay una búsqueda activa que reduce los resultados
- **THEN** se muestra un texto como "Mostrando X de Y storages"

#### Scenario: Contador sin filtros activos
- **WHEN** no hay búsqueda ni filtros activos
- **THEN** se muestra "Mostrando X storages" o equivalente
