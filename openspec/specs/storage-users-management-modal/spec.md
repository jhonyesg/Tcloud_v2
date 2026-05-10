# storage-users-management-modal Specification

## Purpose
Define the in-place modal for managing users assigned to a storage, using a chip-based display with permission badges instead of a table, and a typeahead form for assigning new users.
## Requirements
### Requirement: Modal de gestión de usuarios desde lista de storages
El sistema SHALL abrir un modal in-place al hacer clic en "Usuarios" en la lista de storages, mostrando los usuarios asignados como chips visuales con badges de permisos y un formulario de asignación con typeahead, sin navegar a una página separada.

#### Scenario: Abrir modal de usuarios
- **WHEN** el admin hace clic en "Usuarios" en la columna de acciones de un storage
- **THEN** se abre un modal que muestra la lista de usuarios asignados a ese storage como chips, donde cada chip contiene el `@username` y badges de color para los permisos activos (lectura, escritura, shares)

#### Scenario: Asignar usuario desde el modal
- **WHEN** el admin busca un usuario por username en el typeahead, selecciona permisos y hace clic en "Asignar"
- **THEN** el usuario se asigna al storage, el modal se actualiza mostrando el nuevo usuario como chip con sus badges de permisos correspondientes

#### Scenario: Editar permisos desde el modal
- **WHEN** el admin hace clic en el icono de edición de un chip de usuario
- **THEN** se despliega un panel inline con controles de permisos (checkboxes/toggles para read, write, can_create_shares) que permite modificar y guardar sin cerrar el modal

#### Scenario: Remover usuario desde el modal
- **WHEN** el admin hace clic en el icono de cierre (×) de un chip y confirma
- **THEN** el usuario se remueve del storage y el chip desaparece de la lista

#### Scenario: Cerrar modal
- **WHEN** el admin hace clic fuera del modal, en el botón X, o en "Cerrar"
- **THEN** el modal se cierra y el admin permanece en la lista de storages

### Requirement: Chips de usuarios asignados muestran username y permisos como badges
El sistema SHALL mostrar el username del usuario como identificador principal en cada chip, con el email visible en tooltip o texto secundario, y los permisos activos representados como badges de color diferenciado dentro del mismo chip.

#### Scenario: Visualización de usuario asignado como chip
- **WHEN** el admin ve la lista de usuarios asignados en el modal
- **THEN** cada chip muestra `@username` como texto principal, `email` como texto secundario gris, y badges de permisos activos (por ejemplo: "R" en azul para read, "W" en verde para write, "S" en naranja para shares)

