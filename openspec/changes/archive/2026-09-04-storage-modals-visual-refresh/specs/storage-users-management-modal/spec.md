## MODIFIED Requirements

### Requirement: Modal de gestión de usuarios desde lista de storages
El sistema SHALL abrir un modal in-place al hacer clic en "Usuarios" en la lista de storages, mostrando los usuarios asignados como chips visuales con badges de permisos y un formulario de asignación con typeahead, sin navegar a una página separada. El modal SHALL usar el house style de contenedor (rounded-2xl, shadow-2xl, overlay con blur), labels en mayúsculas pequeñas y footer de acciones con primaria de marca.

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

#### Scenario: Contenedor en house style
- **WHEN** el admin compara el modal de gestión de usuarios con los demás modales de la familia storage
- **THEN** comparten contenedor `rounded-2xl shadow-2xl`, overlay con blur, labels uppercase y footer con primaria de marca