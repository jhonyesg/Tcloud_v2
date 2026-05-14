## Why

Al ingresar al módulo "Mis Archivos", los usuarios ven una lista de storages sin información sobre su estado de disponibilidad. No saben si un storage está accesible o no hasta que intentan navegar a él y falla. Además, la vista actual solo muestra nombre y permisos en una tabla básica, sin capacidad de búsqueda ni ordenamiento.

## What Changes

- Se añade un **campo `is_accessible`** (boolean) y **`last_checked_at`** (timestamp) a la tabla `storage_providers` para indicar si un storage está disponible.
- El endpoint existente `GET /admin/storages/{id}/test` ahora **guarda el resultado** del test de conectividad en estas nuevas columnas.
- El endpoint `GET /user/storages` ahora retorna los campos `accessible` y `last_checked` en la respuesta JSON.
- La vista "Mis Archivos" se mejora con una **tabla sortable** que muestra: Nombre, Permisos, Accesible (con badge de color), y Última verificación.
- Se agrega un **campo de búsqueda** para filtrar storages por nombre.
- Se permite **ordenar por columna** (nombre, permisos, accesible) haciendo clic en los encabezados.

## Capabilities

### Modified Capabilities
- `storage-list-ui`: La vista raíz de "Mis Archivos" ahora muestra storages en tabla sortable con buscador y estado de accesibilidad.
- `storage-provider-model`: Se agregan campos `is_accessible` y `last_checked_at` al modelo.
- `storage-connectivity-test`: El endpoint de test ahora persiste el resultado en BD.

## Impact

- `app/database/migrations/`: Nueva migración para agregar columnas `is_accessible` y `last_checked_at` a `storage_providers`.
- `app/app/Models/StorageProvider.php`: Agregar casts para los nuevos campos.
- `app/app/Http/Controllers/StorageProviderController.php`: Modificar método `test()` para guardar resultado.
- `app/app/Http/Controllers/FileController.php`: Modificar método `storages()` para retornar nuevos campos.
- `app/resources/views/files/index.blade.php`: Reemplazar vista de storages por tabla con buscador y sorting.
