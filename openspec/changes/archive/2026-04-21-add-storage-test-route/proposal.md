## Why

El botón "Probar" en el módulo de Storages (admin/storages) muestra el error "The route admin/storages/1/test could not be found" porque la ruta no está definida en `web.php`, a pesar de que el método `test()` existe en `StorageProviderController`.

## What Changes

- Agregar la ruta `GET /admin/storages/{storage}/test` en `web.php`
- Esta ruta apunta al método `test()` del `StorageProviderController`
- El método ya está implementado y verifica si la ruta configurada es accesible

## Capabilities

### Modified Capabilities

- `storage-management`: La funcionalidad "Probar" ahora funciona correctamente al agregar la ruta faltante

## Impact

- **Archivo afectado**: `routes/web.php`
- **Método del controlador**: `StorageProviderController::test()` (ya existe)
- **Funcionalidad**: El botón "Probar" en la UI de admin/storages podrá llamar correctamente a la ruta y verificar accesibilidad de la ruta configurada
