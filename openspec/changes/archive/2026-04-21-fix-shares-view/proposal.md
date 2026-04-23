## Why

El módulo "Compartidos" retorna JSON en lugar de una vista Blade cuando se accede directamente desde el navegador, mostrando un JSON crudo en lugar de la interfaz de usuario formateada que sí funcionan correctamente en los módulos de Usuarios y Storage.

## What Changes

- Modificar `ShareController::index()` para detectar requests AJAX vs navegación normal
- Cuando es navegación directa (no AJAX): retornar la vista Blade `shares.index` con los datos de shares
- Cuando es AJAX: retornar JSON (comportamiento actual para API calls)
- Mantener consistencia con el patrón usado en `UserController::index()`

## Capabilities

### New Capabilities
- *(ninguno - es corrección de comportamiento existente)*

### Modified Capabilities
- `file-sharing`: El controlador de shares ahora DEBERÁ retornar una vista Blade para navegación normal, no solo JSON

## Impact

- **Archivo afectado**: `app/app/Http/Controllers/ShareController.php`
- **Método afectado**: `index()`
- **Vistas existentes**: `resources/views/shares/index.blade.php` ya existe y está correcta
- **Compatibilidad API**: Las llamadas AJAX existentes seguirán funcionando igual (retorna JSON)
