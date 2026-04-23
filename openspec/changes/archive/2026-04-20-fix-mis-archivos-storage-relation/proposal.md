## Why

El módulo "Mis Archivos" no muestra correctamente los storages disponibles para el usuario, ni refleja los cambios cuando se elimina o agrega un storage. El usuario necesita un selector visual de storages y el sistema debe actualizarse automáticamente cuando la relación usuario-storage cambia.

## What Changes

- Crear endpoint `GET /api/user/storages` para obtener los storages asignados al usuario actual
- Agregar dropdown de selección de storage en "Mis Archivos" (`files/index.blade.php`)
- Al seleccionar un storage, verificar que existe y que el usuario tiene acceso
- Si el storage seleccionado no existe o fue eliminado, limpiar localStorage y mostrar mensaje
- Sincronizar el estado del storage con la lista actualizada al recargar

## Capabilities

### New Capabilities

- `storage-selector`: Capacidad de seleccionar un storage desde una lista de storages disponibles para el usuario. Incluye validación de acceso y limpieza de estado cuando el storage deja de estar disponible.

## Impact

- **Frontend**: `files/index.blade.php` - Agregar dropdown de storages y lógica de sincronización
- **Backend**: Nuevo endpoint `GET /api/user/storages` en FileController o nuevo controlador
- **Modelos**: User, UserStorage, StorageProvider
- **Storage deletion**: El cascade delete ya existe en BD (user_storages y files con FK cascade), no requiere cambios en eliminación
