## Why

El sidebar del layout muestra "2.5 GB / 10 GB" y una barra al 25% completamente hardcodeados, sin importar qué usuario esté logueado ni cuánto espacio real ocupe. Esto hace que el indicador de almacenamiento sea inútil y confuso para todos los usuarios.

## What Changes

- El sidebar del layout dejará de mostrar valores hardcodeados y mostrará datos reales del usuario autenticado.
- Para usuarios normales: usa `personal_used_bytes` y `personal_quota_bytes` del modelo `User`.
- Para admins: muestra el total de almacenamiento usado en el sistema (suma de todos los archivos).
- Si el límite es 0 (ilimitado), se muestra "Ilimitado" en lugar de un divisor.
- Los valores se formatean automáticamente en KB, MB o GB según la magnitud.

## Capabilities

### New Capabilities

- `sidebar-storage-widget`: Widget de almacenamiento en el sidebar que muestra uso real por usuario vía View Composer.

### Modified Capabilities

<!-- Ninguna especificación existente cambia sus requisitos. -->

## Impact

- `app/app/Providers/AppServiceProvider.php` — se registra un View Composer que inyecta `$sidebarQuota` al layout.
- `app/resources/views/layouts/app.blade.php` — el bloque hardcodeado del sidebar se reemplaza con `$sidebarQuota`.
- Modelo `User` — se leen campos `personal_used_bytes` y `personal_quota_bytes` (ya existentes).
- Sin migraciones, sin nuevas dependencias.
