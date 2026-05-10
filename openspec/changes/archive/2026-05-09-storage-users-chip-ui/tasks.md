## 1. Corregir bug del dropdown de búsqueda

- [x] 1.1 Reemplazar `@focus="searchUsers(userSearchQuery)"` por `@click="searchUsers(userSearchQuery || '')"` en el input de búsqueda del modal
- [x] 1.2 Reemplazar `@click="selectUser(user)"` por `@mousedown.prevent="selectUser(user)"` en cada ítem del dropdown para evitar el cierre prematuro por click.away
- [x] 1.3 Verificar que la búsqueda vacía devuelve resultados desde el controller `searchUsers` (sin filtro cuando q = '')

## 2. Rediseñar área de usuarios asignados como chips

- [x] 2.1 Eliminar el `<table>` de usuarios asignados del modal y reemplazar por un contenedor `flex flex-wrap gap-2`
- [x] 2.2 Crear el chip de usuario: `@username` + badge de permisos (coloreado) + botón "×" que llama a `removeAssignmentFromModal`
- [x] 2.3 El click en el cuerpo del chip (no en ×) llama a `openEditAssignment(a)` para editar permisos inline
- [x] 2.4 Mantener el bloque de edición de permisos inline (`showEditAssignment`) debajo de los chips

## 3. Añadir checkbox "Todas las personas"

- [x] 3.1 Añadir estado Alpine `assignAllUsers: false` y método `toggleAssignAll()` en el x-data del componente
- [x] 3.2 Añadir checkbox "Todas las personas" en el modal (encima o debajo del área de chips)
- [x] 3.3 En el controller, añadir método `assignAll(int $id)` que recorre todos los usuarios y asigna los que no están ya asignados con permisos 'read'
- [x] 3.4 Añadir método `removeAll(int $id)` en el controller para desasignar todos los usuarios de un storage
- [x] 3.5 Registrar rutas: `POST /admin/storages/{id}/users/assign-all` y `DELETE /admin/storages/{id}/users/all` en `web.php`
- [x] 3.6 En Alpine: `toggleAssignAll()` llama al endpoint correspondiente según estado del checkbox, muestra confirm(), y recarga `usersModalList` + actualiza chips

## 4. Ajustes visuales del modal

- [x] 4.1 Añadir estado `allAssigned` en Alpine (true si todos los usuarios están asignados) para pre-marcar el checkbox "Todas las personas" al abrir el modal
- [x] 4.2 Verificar que el chip con `full` aparece en verde, `write` en azul, `upload` en amarillo, `read` en gris
- [x] 4.3 Añadir `max-h-40 overflow-y-auto` al contenedor de chips para que no desborde el modal con muchos usuarios
