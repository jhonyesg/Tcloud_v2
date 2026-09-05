## Why

En `/admin/storages` los botones "Eliminar" (Storage) y "Desvincular" (usuario asignado) no muestran ningún feedback visual durante la petición asíncrona. El usuario ve el botón "pensando", asume que no funcionó, hace click de nuevo o recarga la página. El segundo intento sobre el storage/asignación ya borrada devuelve 404 (ModelNotFoundException), aparece un toast de error, y al recargar confirma que la primera petición SÍ eliminó el registro. El backend es correcto; el problema es 100% UX.

## What Changes

- Añadir estado Alpine `deletingStorageId` y `removingUserAssignmentId` para tracking de operaciones en curso.
- Botón "Eliminar" del modal de storage: `:disabled` mientras la petición está en vuelo + texto "Eliminando..." + opacidad reducida.
- Botón × de cada chip de usuario: `:disabled` durante la petición + spinner inline.
- Función `deleteStorage()`: envolver en try/finally para limpiar el estado, hacer `await loadStorages()` para que la lista refresque antes del toast.
- Función `removeAssignmentFromModal()`: añadir rama `else` con toast de error (actualmente falla en silencio) + estado de carga.
- Aplicar el mismo patrón al botón "Probar" como referencia ya correcta (`testingStorage`).

## Capabilities

### New Capabilities
- `admin-storages-loading-state`: Feedback visual durante operaciones destructivas (eliminar storage, desvincular usuario) en el admin de storages. Cubre estados disabled, spinners inline, limpieza garantizada del estado en finally, y toast de error en la rama de fallo de `removeAssignmentFromModal`.

### Modified Capabilities
- (ninguno — los specs existentes describen QUÉ hace cada acción; este cambio describe CÓMO se muestra feedback, lo cual es comportamiento nuevo, no modificación)

## Impact

- **Solo frontend**: `app/resources/views/admin/storages.blade.php`.
- Backend intacto: `StorageProviderController@destroy` y `@removeUserAssignment` siguen devolviendo 200/404 igual que ahora (comportamiento correcto).
- Sin migraciones, sin cambios de API, sin cambios en otros módulos.
- Riesgo: bajo — solo cambios en template Blade + JS Alpine.

## Non-goals

- No cambiar el comportamiento del backend (los 404 sobre recursos ya eliminados son correctos).
- No modificar `storage-users.blade.php` (vista separada para editar asignaciones desde `/admin/users/{id}/storages`) — fuera de scope de este reporte.
- No añadir confirmaciones adicionales ni flujos de "soft delete".
