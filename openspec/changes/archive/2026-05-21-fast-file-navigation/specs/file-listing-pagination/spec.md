## Requirements

### Requirement: Paginacion server-side del endpoint /files
El sistema SHALL aceptar los parametros `page` (default: 1) y `per_page` (default: 100, max: 200) en `GET /files` e incluir metadata de paginacion en la respuesta.

#### Scenario: Primera pagina (comportamiento por defecto)
- **WHEN** el usuario navega a una carpeta sin parametro `page`
- **THEN** el servidor retorna los primeros 100 archivos ordenados por `is_folder DESC, created_at DESC`
- **AND** la respuesta incluye `pagination.total`, `pagination.has_more`, `pagination.page`

#### Scenario: Carpeta con menos de 100 archivos
- **GIVEN** una carpeta con 45 archivos
- **WHEN** se solicita page=1
- **THEN** se retornan los 45 archivos y `pagination.has_more` es `false`

#### Scenario: Pagina fuera de rango
- **WHEN** se solicita una pagina mayor al total de paginas disponibles
- **THEN** el servidor retorna `files: []` y `pagination.has_more: false`

### Requirement: Scroll infinito en frontend
El sistema SHALL cargar el siguiente lote de archivos automaticamente cuando el usuario hace scroll hasta el 80% del contenedor de archivos, y agregarlo al listado existente sin reemplazarlo.

#### Scenario: Carga de lote adicional por scroll
- **GIVEN** el usuario esta viendo 100 archivos de una carpeta con 823 en total
- **WHEN** el usuario hace scroll hasta el 80% del contenedor
- **THEN** se hace fetch de `page=2` y los archivos se agregan al final de la lista visible
- **AND** se muestra un indicador de "cargando mas" mientras dura el fetch

#### Scenario: Ultima pagina cargada
- **GIVEN** el usuario cargo todos los archivos disponibles
- **WHEN** el servidor responde con `pagination.has_more: false`
- **THEN** el scroll infinito se desactiva y no se hacen mas peticiones para esa carpeta

#### Scenario: Cambio de carpeta resetea paginacion
- **WHEN** el usuario navega a una carpeta diferente
- **THEN** `currentPage` se resetea a 1 y `files` se limpia antes de cargar el primer lote

### Requirement: Estado de seleccion masiva se mantiene entre lotes
El sistema SHALL preservar los archivos seleccionados cuando se cargan lotes adicionales via scroll infinito.

#### Scenario: Seleccion persiste al cargar mas archivos
- **GIVEN** el usuario tiene 15 archivos seleccionados de la pagina 1
- **WHEN** el scroll carga la pagina 2
- **THEN** los 15 archivos siguen seleccionados y los nuevos no estan seleccionados
