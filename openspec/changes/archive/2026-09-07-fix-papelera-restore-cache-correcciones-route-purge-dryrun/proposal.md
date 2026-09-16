## Why

Tres pendientes abiertos detectados durante la revisión final del módulo papelera + IA:

1. **`PapeleraController::restore` no invalida el cache del folder de destino.** Después de restaurar un archivo, el browser no lo ve hasta que el TTL del cache (60-300s según carpeta) expire. Detectado durante la validación de `filter-trashed-from-file-browser` cuando `bootTel.dat` no aparecía en Disco_G después del restore.

2. **`Route::delete('/correcciones/{id}', [..., 'destroy'])` no tiene `->whereNumber('id')`.** Si un cliente envía un id no numérico, Laravel lo pasa como string y el controller lanza `TypeError: must be of type int, string given` (visto en logs 2026-09-06 11:34:40 y 11:37:28). Otras rutas del mismo resource SÍ tienen `whereNumber` (línea 277 `update`, etc.). Inconsistencia.

3. **`php artisan trash:purge` no tiene opción `--dry-run`.** El admin que necesite verificar cuántos items se purgarían antes de correr la operación real tiene que inspeccionar BD a mano. Cosmético pero estándar en cualquier comando destructivo.

## What Changes

- **#1 (papelera restore — capability change)**: en `PapeleraController::restore`, capturar `parent_id` y `storage_provider_id` del archivo ANTES del restore (parent original, que es null durante trash) y DESPUÉS del restore (parent de destino). Llamar `StorageSyncService::invalidateFolderCache()` para la clave del parent de destino. Mismo patrón que `FileController@destroy`.
- **#2 (correcciones route — implementation fix)**: en `app/routes/web.php` línea 269, agregar `->whereNumber('id')` a la ruta DELETE de correcciones. Una palabra.
- **#3 (trash:purge --dry-run — implementation fix)**: en `TrashPurgeCommand::signature`, agregar `{--dry-run}` que llama `purgeExpired(... dryRun=true)`. El service `PapeleraService::purgeExpired` recibe un nuevo parámetro opcional `bool $dryRun = false` que, cuando es true, ejecuta todo el flujo hasta identificar candidatos, pero no llama `hardDelete` — solo reporta conteos.

## Capabilities

### Modified Capabilities
- `trash-module`: el restore MUST invalidar el cache del folder de destino para que el archivo restaurado aparezca en el browser inmediatamente.

(#2 y #3 son tooling/implementation fixes — no cambian comportamiento observable por el usuario más allá de devolver un error de validación más limpio en #2 y permitir dry-run en #3. No requieren spec delta.)

## Impact

- `app/app/Modules/Papelera/Http/Controllers/PapeleraController.php` (modifica `restore` — agrega invalidación de cache).
- `app/app/Modules/Papelera/Services/PapeleraService.php` (agrega parámetro `dryRun` a `purgeExpired`).
- `app/app/Console/Commands/TrashPurgeCommand.php` (agrega opción `--dry-run`).
- `app/routes/web.php` línea 269 (agrega `->whereNumber('id')`).
- Sin migraciones, sin cambios de UI, sin cambios de cache layer.

## Non-goals

- Crear un endpoint HTTP equivalente a `--dry-run` para invocar desde la UI del admin. El flag CLI es suficiente; si en el futuro se necesita en UI, se hace como follow-up.
- Auditar TODAS las rutas `DELETE /.../{id}` que falten `whereNumber` (las del módulo papelera, shares, etc.). Solo se cubre correcciones destroy porque es la que tiene log de error; las demás se pueden revisar como follow-up.
- Cambiar el comportamiento de `restore` cuando el parent original está trashed (eso ya está manejado en `PapeleraService::restore` correctamente).
