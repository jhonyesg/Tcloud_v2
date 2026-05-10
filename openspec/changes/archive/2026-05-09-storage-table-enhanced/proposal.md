## Why

La tabla de storages actualmente muestra todos los registros sin capacidad de búsqueda, ordenamiento ni filtrado, lo que la hace inmanejable cuando el número de storages crece. Se necesita una tabla interactiva y escalable para que los administradores puedan localizar y gestionar storages eficientemente.

## What Changes

- La tabla de storages en `/admin/storages` tendrá encabezados de columna clicables para ordenar (asc/desc) por ID, Nombre, Tipo, Archivos y Estado.
- Se añade un cuadro de búsqueda global que filtra resultados en tiempo real por nombre, tipo y estado.
- Se añade paginación del lado del cliente para limitar los registros visibles por página (10, 25, 50).
- Se añaden filtros rápidos por tipo (local, s3) y por estado (activo/inactivo) mediante chips/selects.
- Se muestra un indicador visual del criterio de ordenamiento activo (flecha asc/desc) en los encabezados.
- Los resultados filtrados muestran un contador de registros visibles vs. total.

## Capabilities

### New Capabilities
- `storage-table-sortable`: Ordenamiento asc/desc por columna en la tabla de storages con indicador visual activo.
- `storage-table-search`: Búsqueda en tiempo real por texto libre que filtra por nombre, tipo y estado.
- `storage-table-filters`: Filtros rápidos por tipo de storage y estado (activo/inactivo).
- `storage-table-pagination`: Paginación del lado del cliente con selector de registros por página.

### Modified Capabilities
<!-- No hay specs existentes de la tabla de storages que cambien en sus requisitos. -->

## Impact

- `app/resources/views/admin/storages.blade.php`: Se modifica el bloque Alpine.js y la estructura HTML de la tabla.
- Sin cambios en rutas, controladores ni modelos — toda la lógica es del lado del cliente con Alpine.js.
- Sin nuevas dependencias externas — se utiliza Alpine.js ya disponible en el proyecto.
