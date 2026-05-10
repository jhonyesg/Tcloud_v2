## 1. Backend — Endpoint de búsqueda de usuarios

- [x] 1.1 Añadir método `searchUsers(Request $request)` a `StorageProviderController` que busque por username (LIKE, case-insensitive, max 20 resultados) y retorne `[{id, username, email}]`
- [x] 1.2 Añadir ruta `GET /admin/users/search` en `routes/web.php` con middleware `auth` + `admin`
- [x] 1.3 Actualizar método `users()` en `StorageProviderController` para incluir `username` en la respuesta JSON
- [x] 1.4 Actualizar método `assignUser()` para incluir `username` en la respuesta JSON
- [x] 1.5 Actualizar método `updateUserAssignment()` para incluir `username` en la respuesta JSON

## 2. Frontend — Modal de gestión en storages.blade.php

- [x] 2.1 Cambiar el link "Usuarios" a un botón que abra un modal (`showUsersModal = true`) y pase el storage seleccionado
- [x] 2.2 Añadir estado Alpine.js: `showUsersModal`, `usersModalStorage`, `usersModalList`, `userSearchQuery`, `userSearchResults`, `userSearchSelected`, `showEditAssignment`, `editingAssignment`
- [x] 2.3 Añadir función `loadUsersModal()` que fetch `/admin/storages/{id}/users` y popule `usersModalList`
- [x] 2.4 Añadir función `searchUsers(query)` con debounce de 300ms que fetch `/admin/users/search?q=...` y popule `userSearchResults`
- [x] 2.5 Añadir función `assignUserFromModal()` que POST a `/admin/storages/{id}/users` con el usuario seleccionado del typeahead
- [x] 2.6 Añadir función `updateAssignmentFromModal()` que PUT a `/admin/storages/{id}/users/{userId}`
- [x] 2.7 Añadir función `removeAssignmentFromModal()` que DELETE a `/admin/storages/{id}/users/{userId}`
- [x] 2.8 Crear HTML del modal: tabla de usuarios asignados (username + email, permisos, shares, acciones) + sección de asignación con typeahead
- [x] 2.9 Crear sub-modal o sección inline para editar permisos de un usuario asignado

## 3. Frontend — Typeahead component

- [x] 3.1 Implementar input de búsqueda con `x-model="userSearchQuery"` y `@input.debounce.300ms="searchUsers(userSearchQuery)"`
- [x] 3.2 Implementar dropdown de resultados con `x-show="userSearchResults.length > 0"` mostrando username + email por cada resultado
- [x] 3.3 Implementar selección: al hacer clic en un resultado, setear `userSearchSelected` y cerrar dropdown
- [x] 3.4 Mostrar mensaje "Sin resultados" cuando la búsqueda no retorna usuarios

## 4. Frontend — Actualizar storage-users.blade.php

- [x] 4.1 Reemplazar el `<select>` de usuarios por el componente typeahead
- [x] 4.2 Actualizar la tabla de usuarios asignados para mostrar username como texto principal y email como secundario
- [x] 4.3 Actualizar la visualización en el modal de edición para mostrar username
