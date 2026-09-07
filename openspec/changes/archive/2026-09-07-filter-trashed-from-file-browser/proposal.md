## Why

`FileController@index` (que sirve el JSON del explorador de archivos cuando Alpine pide `Accept: application/json`) ejecuta una query con `whereNull('parent_id')` para el root listing, pero NO filtra por `is_trashed=false`. Resultado: cualquier archivo con `parent_id=NULL` + `is_trashed=true` aparece en el root listing del explorador — un leak que rompe el contrato "trashed = solo visible en /papelera".

Esto lo detectamos durante la validación del fix de cache invalidation (`fix-papelera-cache-invalidation-on-trash`): el archivo `bootTel.dat` trashado aparecía en `/files?storage_id=5` con `is_trashed=true` visible al cliente.

El fix de cache invalidation cierra la ventana TTL (60s) pero si esa invalidación falla (lock tomado, error silencioso, código futuro que olvide invalidar), el leak persiste. La query misma debe ser correcta — defense in depth.

## What Changes

- En `FileController@index` (rama AJAX), agregar `->where('is_trashed', false)` a la query base, antes del `where` por parent_id.
- Sin cambios en `StorageSyncService` (ya filtra), sin cambios en `PapeleraController` (la papelera es el único lugar donde se listan trashed), sin migraciones.

## Capabilities

### Modified Capabilities
- `trash-module`: el listado del file browser (`/files` AJAX) MUST NOT retornar filas con `is_trashed=true` en ninguna rama de la query (root o subfolder).

## Impact

- `app/app/Http/Controllers/FileController.php` (1 línea modificada en `index()`).
- Sin migraciones, sin cambios de servicio.

## Non-goals

- Reescribir toda la query del explorador (usar join, eager-load, etc.). Cambio mínimo.
- Tocar `PapeleraController` o el help panel — siguen correctos.
- Añadir índice a `files(is_trashed)` — el índice parcial `files_trash_sweep_idx` que ya existe cubre los escaneos del cron; las queries del browser siempre tienen `storage_provider_id` + `parent_id` que ya están indexados, y `is_trashed=false` filtra el ~99% de filas.
- Tocar otros controllers que puedan tener el mismo leak (`ShareController`, `MediaEditJobController`).
