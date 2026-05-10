## ADDED Requirements

### Requirement: Usuarios asignados se muestran como chips removibles

El modal de usuarios del storage SHALL mostrar los usuarios ya asignados como chips/etiquetas con el formato `@username` y un botón "×" para removerlos, en lugar de una tabla.

#### Scenario: Chips visibles al abrir el modal

- **WHEN** el admin abre el modal de usuarios de un storage que tiene usuarios asignados
- **THEN** cada usuario aparece como un chip con `@username`, badge de color según sus permisos, y botón "×"

#### Scenario: Remover usuario desde chip

- **WHEN** el admin hace clic en "×" de un chip de usuario
- **THEN** se elimina la asignación y el chip desaparece del modal sin recargar la página

#### Scenario: Modal sin usuarios asignados

- **WHEN** el admin abre el modal de un storage sin usuarios
- **THEN** se muestra un mensaje vacío en lugar de chips

### Requirement: Edición de permisos desde chip

El sistema SHALL permitir editar los permisos de un usuario asignado haciendo clic en su chip (excepto en el botón ×).

#### Scenario: Click en chip abre formulario de edición

- **WHEN** el admin hace clic sobre el cuerpo del chip (no en ×)
- **THEN** se muestra un formulario inline con los permisos actuales del usuario para modificarlos

### Requirement: Dropdown de búsqueda muestra resultados al enfocar el input

El dropdown de búsqueda de usuarios SHALL mostrarse con todos los usuarios disponibles al hacer clic en el input, sin necesidad de escribir primero.

#### Scenario: Clic en input muestra lista de usuarios

- **WHEN** el admin hace clic en el campo de búsqueda de usuario
- **THEN** el dropdown se abre mostrando hasta 20 usuarios disponibles (no ya asignados preferiblemente)

#### Scenario: Búsqueda filtra por texto

- **WHEN** el admin escribe texto en el campo de búsqueda
- **THEN** el dropdown filtra los resultados por username o email coincidente

#### Scenario: Selección de usuario desde dropdown

- **WHEN** el admin hace clic sobre un resultado del dropdown
- **THEN** el usuario queda seleccionado y el dropdown se cierra sin bug de doble click

### Requirement: Checkbox "Todas las personas" asigna todos los usuarios

El sistema SHALL ofrecer un checkbox "Todas las personas" que asigne todos los usuarios del sistema al storage con permisos de lectura.

#### Scenario: Marcar "Todas las personas"

- **WHEN** el admin marca el checkbox "Todas las personas" y confirma
- **THEN** todos los usuarios del sistema se asignan al storage (los ya asignados no se duplican)
- **THEN** los chips se actualizan mostrando todos los usuarios

#### Scenario: Desmarcar "Todas las personas"

- **WHEN** el admin desmarca el checkbox "Todas las personas" y confirma
- **THEN** todos los usuarios se remueven del storage
- **THEN** el área de chips queda vacía
