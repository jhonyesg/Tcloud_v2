## Tasks

### 1. Migración
- [x] Crear migración para agregar columnas `is_accessible` (boolean, default false) y `last_checked_at` (timestamp nullable) a tabla `storage_providers`
- [x] Ejecutar migración

### 2. Backend - Modelo
- [x] Actualizar `StorageProvider.php` con casts para `is_accessible` y `last_checked_at`

### 3. Backend - Controlador de Test
- [x] Modificar método `test()` en `StorageProviderController.php` para guardar resultado del test en `is_accessible` y `last_checked_at`

### 4. Backend - Endpoint de Storages
- [x] Modificar método `storages()` en `FileController.php` para incluir `accessible` y `last_checked` en la respuesta JSON

### 5. Frontend - Tabla de Storages
- [x] Agregar estado Alpine.js: `storageSearchQuery`, `storageSortField`, `storageSortDirection`
- [x] Crear función `filteredStorages()` con filtrado por nombre y ordenamiento por columna
- [x] Crear función `toggleStorageSort(field)` para alternar dirección de ordenamiento
- [x] Reemplazar vista actual de storages (grid/list) por tabla con encabezados sortables
- [x] Agregar badge de color para estado accesible (verde/rojo/gris)
- [x] Agregar campo de búsqueda sobre la tabla
- [x] Mantener comportamiento de click para entrar al storage

### 6. Limpieza
- [x] Verificar que la funcionalidad existente de "Probar" en admin sigue funcionando
- [x] Probar que el auto-enter cuando solo hay 1 storage sigue funcionando
