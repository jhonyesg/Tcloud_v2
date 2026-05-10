## ADDED Requirements

### Requirement: Modal de gestión de usuarios desde lista de storages
El sistema SHALL abrir un modal in-place al hacer clic en "Usuarios" en la lista de storages, mostrando la tabla de usuarios asignados y un formulario de asignación con typeahead, sin navegar a una página separada.

#### Scenario: Abrir modal de usuarios
- **WHEN** el admin hace clic en "Usuarios" en la columna de acciones de un storage
- **THEN** se abre un modal que muestra la lista de usuarios asignados a ese storage con username, permisos, shares y acciones (editar/remover)

#### Scenario: Asignar usuario desde el modal
- **WHEN** el admin busca un usuario por username en el typeahead, selecciona permisos y hace clic en "Asignar"
- **THEN** el usuario se asigna al storage, el modal se actualiza mostrando el nuevo usuario en la tabla

#### Scenario: Editar permisos desde el modal
- **WHEN** el admin hace clic en "Editar" junto a un usuario asignado
- **THEN** se muestra un formulario inline o sub-modal para modificar permisos y can_create_shares

#### Scenario: Remover usuario desde el modal
- **WHEN** el admin hace clic en "Remover" y confirma
- **THEN** el usuario se remueve del storage y desaparece de la tabla

#### Scenario: Cerrar modal
- **WHEN** el admin hace clic fuera del modal, en el botón X, o en "Cerrar"
- **THEN** el modal se cierra y el admin permanece en la lista de storages

### Requirement: Tabla de usuarios asignados muestra username
El sistema SHALL mostrar el username del usuario como identificador principal en la tabla de usuarios asignados, con el email como texto secundario.

#### Scenario: Visualización de usuario asignado
- **WHEN** el admin ve la tabla de usuarios asignados en el modal o en la página separada
- **THEN** cada fila muestra `@username` como texto principal y `email` en texto secundario gris
