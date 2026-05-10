# user-search-typeahead Specification

## Purpose
TBD - created by archiving change storage-users-modal-username. Update Purpose after archive.
## Requirements
### Requirement: Búsqueda de usuarios por username con typeahead
El sistema SHALL proporcionar un componente typeahead que busca usuarios por username mediante un endpoint AJAX, mostrando resultados con username y email.

#### Scenario: Buscar usuario por username
- **WHEN** el admin escribe caracteres en el campo de búsqueda de usuarios
- **THEN** después de un debounce de 300ms, se realiza una petición GET a `/admin/users/search?q=<query>` y se muestran hasta 20 resultados con username y email

#### Scenario: Seleccionar usuario del typeahead
- **WHEN** el admin hace clic en un resultado del typeahead
- **THEN** el campo de búsqueda se rellena con el username seleccionado, se almacena el user_id internamente, y se cierra el dropdown de resultados

#### Scenario: Sin resultados
- **WHEN** la búsqueda no retorna usuarios
- **THEN** se muestra un mensaje "Sin resultados" en el dropdown

#### Scenario: Campo vacío
- **WHEN** el campo de búsqueda está vacío
- **THEN** no se realiza ninguna búsqueda y el dropdown de resultados permanece cerrado

### Requirement: Endpoint de búsqueda de usuarios
El sistema SHALL exponer un endpoint `GET /admin/users/search?q=<query>` que retorne hasta 20 usuarios cuyo username contenga el query (case-insensitive).

#### Scenario: Búsqueda válida
- **WHEN** el admin realiza GET `/admin/users/search?q=juan` con sesión de admin activa
- **THEN** el sistema responde con un JSON array de objetos `{id, username, email}` filtrados por username que contenga "juan"

#### Scenario: Query vacío
- **WHEN** el admin realiza GET `/admin/users/search?q=` o sin parámetro q
- **THEN** el sistema responde con un array vacío

#### Scenario: Sin permisos de admin
- **WHEN** un usuario no-admin intenta acceder al endpoint
- **THEN** el sistema responde con 403 Forbidden

