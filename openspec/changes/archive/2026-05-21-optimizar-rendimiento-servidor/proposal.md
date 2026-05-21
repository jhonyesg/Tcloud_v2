## Why

El servidor acumulaba procesos PHP-FPM hasta agotar recursos, forzando reinicios manuales. La causa raíz es una combinación de configuración PHP-FPM sobredimensionada (300 workers × 1 GB = 300 GB teórico en un servidor de 78 GB), tareas programadas sin protección contra solapamiento, y consultas DB innecesarias en cada request HTTP.

## What Changes

- **PHP-FPM** (`/www/server/php/84/etc/php-fpm.conf`): reducir `pm.max_children` de 300 → 50, `memory_limit` de 1024M → 256M, agregar `pm.max_requests = 500` para reciclar workers, ajustar spare servers.
- **`console.php`**: agregar `->withoutOverlapping()` al comando `storage:sync --all` para evitar acumulación de procesos cuando el sync tarda más de 15 min.
- **`SessionTracker` middleware**: cachear en Redis el resultado de la consulta a `user_sessions` con TTL de 30 s para evitar una query DB en cada request web.
- **`FileController::deleteRecursive`**: usar eager loading de `storageProvider` al cargar hijos para eliminar el N+1 en eliminaciones masivas.
- **`User::hasStoragePermission`**: memoizar el resultado de `userStorages` dentro del ciclo de vida del request para evitar queries repetidas por permiso.

## Capabilities

### New Capabilities
- `php-fpm-tuning`: Parámetros óptimos de PHP-FPM para un servidor con 78 GB RAM y carga mixta de uploads/downloads/sync.
- `session-tracker-cache`: SessionTracker cachea la validación de sesión en Redis para reducir queries DB por request.
- `storage-sync-overlap-guard`: El comando `storage:sync --all` no puede ejecutarse en paralelo consigo mismo.

### Modified Capabilities
*(ninguna — los cambios son de implementación, no de requisitos funcionales)*

## Impact

- `app/routes/console.php` — agregar `->withoutOverlapping()`
- `app/app/Http/Middleware/SessionTracker.php` — agregar caché Redis
- `app/app/Http/Controllers/FileController.php` — eager loading en `deleteRecursive`, memoización en llamadas a `hasStoragePermission`
- `app/app/Models/User.php` — método `hasStoragePermission` con memoización por request
- `/www/server/php/84/etc/php-fpm.conf` — ajuste de pool `[www]`
- No requiere migración de base de datos
- Requiere reinicio de PHP-FPM tras cambio de configuración

## Non-goals

- No se implementa X-Accel-Redirect para streaming de video/ZIP (cambio mayor de infraestructura, se deja para una fase posterior).
- No se cambia el driver de sesiones ni la arquitectura de auth.
- No se agregan índices de BD adicionales en esta fase.
