## Why

La gestión de usuarios en storages admin requiere dos mejoras de UX: (1) la acción "Usuarios" en la lista de storages navega a una página separada, lo que rompe el flujo del admin — debería abrir un modal in-place; (2) la selección de usuarios usa un dropdown plano con emails, que no escala y es difícil de navegar — debería ser un typeahead por username.

## What Changes

- Reemplazar el link "Usuarios" en `storages.blade.php` por un botón que abre un modal in-place con la lista de usuarios asignados y el formulario de asignación.
- Añadir un endpoint de búsqueda de usuarios (`GET /admin/users/search?q=...`) que retorne `id`, `username` y `email` filtrados por query.
- Reemplazar el `<select>` de usuarios por un componente typeahead que busca por username y muestra username + email.
- Actualizar la tabla de usuarios asignados para mostrar `username` (con email como subtítulo).
- Actualizar los endpoints de la API (`users`, `assignUser`, `updateUserAssignment`) para incluir `username` en las respuestas.
- Mantener `storage-users.blade.php` como página separada funcional (no romper URLs existentes).

## Capabilities

### New Capabilities
- `storage-users-management-modal`: Modal in-place en la lista de storages para gestionar usuarios asignados (ver, asignar, editar, remover) sin navegar a página separada.
- `user-search-typeahead`: Typeahead/búsqueda de usuarios por username con resultados que muestran username y email.

### Modified Capabilities

## Impact

- **Frontend**: `resources/views/admin/storages.blade.php` — se añade modal completo de gestión de usuarios. `resources/views/admin/storage-users.blade.php` — se actualiza el select a typeahead y se cambia visualización a username.
- **Backend**: `StorageProviderController.php` — se añade método `searchUsers()`, se actualizan respuestas JSON para incluir `username`. `routes/web.php` — se añade ruta de búsqueda.
- **Dependencias**: Ninguna nueva.
- **Riesgo**: Bajo. Cambios de UI y un endpoint nuevo. No se modifica lógica de negocio existente.
