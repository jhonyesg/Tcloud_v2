# storage-users-chip-ui Specification

## Purpose
Define the chip-based UI for displaying and managing users assigned to a storage, replacing the previous table-based layout with a compact, interactive chip list that supports inline editing, filtering, and bulk selection.

## Requirements

### Requirement: Chips UI para usuarios asignados
El sistema SHALL mostrar los usuarios asignados a un storage como chips visuales compactos dentro del modal de gestión, en lugar de una tabla, permitiendo identificar y accionar sobre cada usuario de forma rápida.

#### Scenario: Visualización de usuarios como chips
- **WHEN** el admin abre el modal de gestión de usuarios de un storage
- **THEN** cada usuario asignado se muestra como un chip con su `@username` visible y badges que indican sus permisos activos (lectura, escritura, shares)

#### Scenario: Chip con permisos como badges
- **WHEN** se renderizan los chips de usuarios
- **THEN** cada chip incluye badges de color diferenciado para cada permiso activo del usuario (por ejemplo: "R", "W", "S" para read/write/shares)

### Requirement: Editar permisos desde el chip
El sistema SHALL permitir al admin editar los permisos de un usuario directamente desde su chip, sin necesidad de abrir una tabla o navegar a otra vista.

#### Scenario: Abrir edición desde chip
- **WHEN** el admin hace clic en el icono de edición de un chip de usuario
- **THEN** se despliega un panel o popover inline con los controles de permisos del usuario (checkboxes o toggles para read, write, can_create_shares)

#### Scenario: Guardar cambios de permisos desde chip
- **WHEN** el admin modifica los permisos en el panel inline y confirma
- **THEN** los cambios se persisten y el chip se actualiza reflejando los nuevos badges de permisos

#### Scenario: Remover usuario desde chip
- **WHEN** el admin hace clic en el icono de cierre (×) del chip y confirma la acción
- **THEN** el usuario se remueve del storage y el chip desaparece de la lista

### Requirement: Lista de usuarios filtrable
El sistema SHALL proveer un campo de búsqueda/filtro sobre la lista de chips de usuarios asignados, para facilitar la localización de usuarios en storages con muchos asignados.

#### Scenario: Filtrar usuarios por texto
- **WHEN** el admin escribe en el campo de filtro del modal
- **THEN** solo se muestran los chips cuyo username o email contengan el texto ingresado; los demás chips se ocultan

#### Scenario: Limpiar filtro
- **WHEN** el admin borra el texto del campo de filtro o hace clic en el botón de limpiar
- **THEN** se vuelven a mostrar todos los chips de usuarios asignados

#### Scenario: Sin resultados de filtro
- **WHEN** el filtro no coincide con ningún usuario asignado
- **THEN** se muestra un mensaje indicando que no hay usuarios que coincidan con la búsqueda

### Requirement: Checkbox "Todas las personas"
El sistema SHALL incluir una opción "Todas las personas" que, al activarse, asigne automáticamente el storage a todos los usuarios del sistema con un conjunto de permisos predeterminado.

#### Scenario: Activar "Todas las personas"
- **WHEN** el admin marca el checkbox "Todas las personas" en el modal de gestión
- **THEN** el storage queda marcado como accesible para todos los usuarios y se muestra un indicador visual de este estado global

#### Scenario: Desactivar "Todas las personas"
- **WHEN** el admin desmarca el checkbox "Todas las personas"
- **THEN** el storage vuelve al modo de asignación individual y se muestra la lista de chips de usuarios asignados previamente

#### Scenario: Estado "Todas las personas" reflejado en la lista de storages
- **WHEN** un storage tiene activo el modo "Todas las personas"
- **THEN** la columna de usuarios en la lista de storages muestra un indicador especial (por ejemplo: badge "Todos") en lugar del conteo de usuarios individuales
