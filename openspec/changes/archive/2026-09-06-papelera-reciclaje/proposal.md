## Why

El explorador de archivos ejecuta `deleteRecursive()` directo sobre `File` + disco. Eso provoca tres problemas concretos:

1. **Bug actual** (2026-09-06): cuando un sub-folder tiene archivos problemáticos, `@rmdir` falla silenciosamente, `$folder->delete()` igual corre y la fila se va de BD; el sync escanea el padre en disco, encuentra el dir no-vacío y **recrea la fila** → "algunos se eliminan, otros reaparecen".
2. **No hay undo**: borrar es destructivo. Una equivocación (carpeta mal seleccionada) se paga sin red de seguridad.
3. **Shares quedan colgados**: un share_link público muere al instante, sin mensaje claro al visitante.

Un módulo de **papelera de reciclaje** con soft-delete (`is_trashed=1`, `deleted_at`) resuelve los tres frentes: (a) el sync ya no recrea filas que siguen marcadas, (b) el usuario puede restaurar, (c) los shares saben distinguir "borrado duro" de "en papelera" y devuelven un mensaje útil.

## What Changes

- Migration nueva: `files.+deleted_at`, `files.+is_trashed`, `files.+original_parent_id`, índice parcial `(deleted_at) WHERE is_trashed=true`.
- Módulo nuevo `app/app/Modules/Papelera/` con `PapeleraService`, `PapeleraController`, `Commands/TrashPurge`, vista `index.blade.php`.
- `FileController@destroy` deja de hacer hard-delete y llama `PapeleraService::softTrash()`. El hard-delete (purgar) se mueve a `PapeleraService::hardDelete()` con guardarraíl `isFileLinked()`.
- `StorageSyncService::doSyncFolder()` aprende a **no tocar** filas con `is_trashed=true` (ni update, ni recreate).
- Nuevo comando `php artisan trash:purge` (scheduled diario) que barre `WHERE is_trashed=true AND deleted_at < NOW() - retention_days` y hard-deletea con lock + guardarraíl de ratio.
- Sidebar del layout principal: nueva entrada "Papelera" con badge de conteo de items próximos a expirar.
- `config/trash.php` con `retention_days=15`, `purge_batch_size=500`, `purge_max_ratio=0.5`.
- Cambios en `FileController@index` para que su listado principal excluya automáticamente `is_trashed=true` (no necesita UI nueva: el `whereNull('parent_id')` ya filtra, pero añadimos el flag explícito por defensa).
- Nuevo harness de regresión: `tests/harness_papelera_lifecycle.php`.

## Capabilities

### New Capabilities
- `trash-module`: ciclo de vida soft-trash → restore → hard-delete, retención configurable, cron de purga, integración con sync para que no pise filas marcadas, UI lateral con acciones de restaurar / eliminar definitivamente. La regla de "sync no pisa trashed rows" es un requirement interno de este capability (no requiere capability aparte).

### Modified Capabilities
<!-- Ninguno: la regla de sync para filas trashed vive como requirement dentro de `trash-module`. No alteramos observablemente ningún capability existente; `StorageSyncService` se modifica pero su contrato externo (escaneo → reconciliación) sigue igual. -->

## Impact

- `app/database/migrations/<nueva>_add_trash_columns_to_files.php` (nueva).
- `app/config/trash.php` (nuevo).
- `app/app/Modules/Papelera/` (nuevo): Controller, Service, Command, views.
- `app/app/Http/Controllers/FileController.php` (modifica `destroy`).
- `app/app/Services/StorageSyncService.php` (skip trashed rows en `doSyncFolder`, `createFileFromScan`, prune guard).
- `app/routes/web.php` (rutas `/papelera`, `POST /papelera/{id}/restore`, `POST /papelera/empty`).
- `app/routes/console.php` (`Schedule::command('trash:purge')->daily()`).
- `app/resources/views/layouts/app.blade.php` (sidebar: entrada "Papelera" + badge).
- `app/app/Models/File.php` (casts para `is_trashed`, `deleted_at`, scope `trashed` / `notTrashed`).
- `app/tests/harness_papelera_lifecycle.php` (nuevo).

## Non-goals

- Papelera en disco/storage separado (queda para v2 si el almacenamiento principal se llena).
- Retención por usuario o por storage (solo global en config).
- Email/notificación cuando algo entra a papelera.
- Restore selectivo de hijos sin el padre (la unidad de restore es un item; el padre se restaura aparte si también fue trashado).
- Auto-vaciar papelera del usuario desde UI (solo "vaciar toda" global, con confirmación doble).
- Mover archivos físicos a un subdirectorio `.trash/` (MVP usa soft-flag, archivo queda en su sitio).
- Compresión o dedup de la papelera.
- Quitar el bug latente de `@rmdir` silencioso en `deleteRecursive` (se aborda en el hard-delete de purga, pero el archivo legacy queda como follow-up).
