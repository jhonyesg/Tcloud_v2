## MODIFIED Requirements

### Requirement: Modal de gestión de usuarios desde lista de storages

El sistema SHALL abrir un modal in-place al hacer clic en "Usuarios" en la lista de storages, mostrando los usuarios asignados como chips removibles y un formulario de asignación con typeahead funcional, sin navegar a una página separada.

#### Scenario: Abrir modal de usuarios

- **WHEN** el admin hace clic en "Usuarios" en la columna de acciones de un storage
- **THEN** se abre un modal que muestra los usuarios asignados como chips con `@username`, badge de permisos y botón "×"

#### Scenario: Asignar usuario desde el modal

- **WHEN** el admin hace clic en el input de búsqueda, selecciona un usuario del dropdown, elige permisos y hace clic en "Asignar"
- **THEN** el usuario se asigna al storage y aparece como nuevo chip en el área de usuarios asignados

#### Scenario: Editar permisos desde el modal

- **WHEN** el admin hace clic sobre el cuerpo de un chip (no en ×)
- **THEN** se muestra un formulario inline para modificar permisos y can_create_shares del usuario seleccionado

#### Scenario: Remover usuario desde el modal

- **WHEN** el admin hace clic en "×" de un chip y confirma
- **THEN** el usuario se remueve del storage y su chip desaparece

#### Scenario: Cerrar modal

- **WHEN** el admin hace clic fuera del modal, en el botón X, o en "Cerrar"
- **THEN** el modal se cierra y el admin permanece en la lista de storages

### Requirement: Tabla de usuarios asignados muestra username

El sistema SHALL mostrar el username del usuario como identificador principal en los chips de usuarios asignados, con el nivel de permisos visible como badge de color.

#### Scenario: Visualización de usuario asignado como chip

- **WHEN** el admin ve el área de chips en el modal
- **THEN** cada chip muestra `@username` como texto y un badge de color según permisos (read=gris, write=azul, upload=amarillo, full=verde)
