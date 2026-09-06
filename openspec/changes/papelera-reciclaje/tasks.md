## 1. Schema, config y permisos base

- [x] 1.1 Crear migración `2026_09_06_140000_add_trash_columns_to_files.php` con: `deleted_at TIMESTAMP NULL`, `is_trashed BOOLEAN NOT NULL DEFAULT false`, `original_parent_id BIGINT NULL REFERENCES files(id) ON DELETE SET NULL`, e índice parcial `CREATE INDEX files_trash_sweep_idx ON files (deleted_at) WHERE is_trashed = true`. Down migration: drop index + drop columns.
- [x] 1.2 Crear `app/config/trash.php` con: `retention_days=15`, `purge_batch_size=500`, `purge_max_ratio=0.5`, `lock_ttl=600`. Verificar que esté mergeado en `config/app.php` o `config/services.php` si el proyecto centraliza ahí los requires.

## 2. Modelos y scope Eloquent

- [x] 2.1 En `app/app/Models/File.php`: añadir `deleted_at`, `is_trashed`, `original_parent_id` a `$fillable`. Añadir a `$casts`: `is_trashed => 'boolean'`, `deleted_at => 'datetime'`.
- [x] 2.2 Añadir scopes `scopeTrashed($q)` y `scopeNotTrashed($q)` en `File`. Verificar que `File::notTrashed()->where('parent_id', $id)` funciona en queries existentes (no romper nada).

## 3. PapeleraService (lógica core)

- [x] 3.1 Crear `app/app/Modules/Papelera/Services/PapeleraService.php` con constructor que inyecta el registry / sync / quota services que use. (Inyección o `app()` — seguir patrón del proyecto.)
- [x] 3.2 Implementar `softTrash(File $file, ?int $actorUserId): void`: marca `is_trashed=true`, `deleted_at=now()`, `original_parent_id=$file->parent_id`, `parent_id=NULL`. Si es folder, recursión a hijos con su propio snapshot de flags.
- [x] 3.3 Implementar `restore(File $file, ?int $actorUserId): File`: resuelve parent destino (original si existe y no trashed, sino null = root), resuelve colisión de nombre con sufijo `-restored-<unix_ts>`, limpia flags. Devuelve la File actualizada.
- [x] 3.4 Implementar `hardDelete(File $file, ?int $actorUserId): void`: llama `StorageSyncService::isFileLinked($id)`; si true, log warning y skip. Si no, llama a una versión limpia de `deleteRecursive/deleteFile` (sin `@rmdir` silencioso; si rmdir falla, log error pero sigue con `$file->delete()` para no dejar zombie en BD).
- [x] 3.5 Implementar `purgeExpired(int $batchSize, float $maxRatio): int`: adquiere `Cache::lock('trash:purge', 600)`, hace count de candidatos vs total del storage, aborta con log si ratio > max. Itera `chunkById` llamando `hardDelete` por cada uno. Devuelve count borrado.
- [x] 3.6 Implementar `countFor(int $userId, bool $includeUrgent = false): array` que devuelve `['total' => N, 'urgent' => M]` (urgent = menos de 3 días restantes). Cacheado por 60s en Redis.

## 4. StorageSyncService: respetar `is_trashed`

- [x] 4.1 En `doSyncFolder` (línea ~179): cambiar `File::where('storage_provider_id', $storage->id)->where('parent_id', $parentId)->get()` a `...->where('is_trashed', false)->get()`. Esto evita que el sync liste filas trashed para matchear contra el escaneo.
- [x] 4.2 En el loop de update (línea ~224): si la `$existingFile` tiene `is_trashed=true` (no debería pasar por el filtro anterior, pero defensivo), skip con `continue`.
- [x] 4.3 En `createFileFromScan` (línea ~398): antes de `$this->registry->ensure(...)`, comprobar si ya existe una fila con el mismo `path` y `storage_provider_id` con `is_trashed=true`. Si existe, devolver esa fila (o null = "no crear") y loggear `storage_sync.skipped_trashed_collision`.
- [x] 4.4 Probar manualmente que una carpeta soft-trashed no reaparece tras sync (manual test con storage local temp).

## 5. Rutas, controllers y comandos

- [x] 5.1 Crear `app/app/Modules/Papelera/Controllers/PapeleraController.php` con métodos: `index()` (lista paginada), `restore($id)` (POST), `destroy($id)` (DELETE = hard-delete), `empty()` (POST = vaciar todas del usuario actual).
- [x] 5.2 Registrar rutas en `app/routes/web.php` dentro del grupo auth: `GET /papelera` → `PapeleraController@index`; `POST /papelera/{id}/restore`; `DELETE /papelera/{id}`; `POST /papelera/empty`. Nombre las rutas (`name('papelera.index')`, etc.) para que el sidebar pueda usar `route()`.
- [x] 5.3 Crear `app/app/Modules/Papelera/Commands/TrashPurgeCommand.php` extendiendo `Illuminate\Console\Command`. Nombre signature: `trash:purge {--batch=500} {--max-ratio=0.5}`. Llama `PapeleraService::purgeExpired($batch, $maxRatio)` y muestra el conteo.
- [x] 5.4 Registrar el comando en `app/app/Console/Kernel.php` (o autodiscovery si aplica) y programar en `app/routes/console.php`: `Schedule::command('trash:purge')->dailyAt('03:17')->withoutOverlapping(15)->runInBackground()`.
- [x] 5.5 Registrar `PapeleraService` en `AppServiceProvider` como singleton (sigue el patrón del proyecto).

## 6. UI: Sidebar y vista de papelera

- [x] 6.1 En `app/resources/views/layouts/app.blade.php` (o el partial que renderice el sidebar), añadir entrada "Papelera" con icono. Usar `app(PapeleraService::class)->countFor(session('user_id'))` para el badge. Badge rojo si `urgent > 0`, gris si no.
- [x] 6.2 Crear `app/app/Modules/Papelera/resources/views/index.blade.php` extendiendo `layouts.app`. Tabla con columnas: checkbox, nombre, fecha trash, días restantes (color rojo si <3), ubicación original, acciones (Restaurar, Eliminar definitivamente). Alpine state: `items`, `selectedItems`, `confirming`. Acciones llaman a los endpoints.
- [x] 6.3 Estilos: usar el `brand-500` (#4654a8) ya configurado para botones, gris para chrome. Modal de confirmación con el mismo componente que usa `files/index.blade.php` para `deleteConfirmFile` (reusar, no duplicar).

## 7. FileController: destroy pasa a soft-trash

- [x] 7.1 En `app/app/Http/Controllers/FileController.php:392` (`destroy`): reemplazar el cuerpo `deleteRecursive / deleteFile` por una llamada a `app(PapeleraService::class)->softTrash($file, $user->id)`. Mantener el mismo response JSON `['message' => 'Moved to trash']`. Mantener los checks de permiso (`checkFilePermission`, owner/admin) sin cambios.
- [x] 7.2 Confirmar que el frontend `executeDeleteSelected` en `app/resources/views/files/index.blade.php:1191` sigue funcionando (mensaje cambia de "Deleted" a "Moved to trash" — actualizar toast si aplica).
- [x] 7.3 Verificar que `invalidateFolderCache` se llama después del soft-trash (mismo punto que después del sync). Añadir si falta.

## 8. PublicShareController: 410 Gone para trash

- [x] 8.1 En `app/app/Http/Controllers/PublicShareController.php`, dentro del método `show` (o equivalente que resuelve file desde token): después de cargar el File, comprobar `if ($file->is_trashed) return response()->json(['error' => 'file_in_trash', 'message' => '...'], 410);`. Idem para `delete` por si existe endpoint similar.
- [x] 8.2 Smoke test: crear share público, soft-trashear el archivo subyacente, abrir `/s/{token}` y verificar 410 con mensaje correcto.

## 9. Harness de regresión

- [x] 9.1 Crear `tests/harness_papelera_lifecycle.php` con bootstrap Laravel + Postgres. Setup: crea storage local temp con 1 carpeta `parent` y dentro 1 archivo `sample.txt`.
- [x] 9.2 Test soft-trash: `softTrash(parent)` → assertea `parent.is_trashed=true`, `parent.deleted_at!=null`, `parent.original_parent_id=null` (era root), `parent.parent_id=null`, `sample.txt` también trashed, archivo en disco intacto.
- [x] 9.3 Test sync respeta trash: `syncFolderWithReport(storage, null)` → assertea que `parent` NO aparece en el reporte (sync respeta flag). Confirmar también que `sample.txt` no fue recreado.
- [x] 9.4 Test restore: `restore(parent)` → assertea `parent.parent_id=null` (root), `parent.is_trashed=false`, `parent.original_parent_id=null`. Crear colisión: re-trash y re-restore con un archivo del mismo nombre en root → assertea sufijo `-restored-<ts>`.
- [x] 9.5 Test hard-delete: `hardDelete(parent)` → assertea fila borrada, archivo en disco borrado.
- [x] 9.6 Test purge: insertar manualmente una fila trash con `deleted_at = NOW() - 20 days`, correr `purgeExpired(100, 0.5)` → assertea que se borró.
- [x] 9.7 Cleanup en `finally` con el patrón conocido (StorageProvider, User, File rows, tempdir).
- [x] 9.8 Ejecutar: `php tests/harness_papelera_lifecycle.php` desde `app/`. Esperar exit 0.

## 10. Verificación y deploy

- [x] 10.1 Correr `php -l` sobre cada archivo nuevo/modificado.
- [x] 10.2 Smoke test manual end-to-end: crear archivo en una carpeta → eliminar → ver toast "Movido a papelera" → ir a `/papelera` → restaurar → confirmar que vuelve a la carpeta original.
- [x] 10.3 Smoke test mass-delete guardrail: insertar 1000 filas trash artificialmente viejas con `personal_used_bytes` no alterado, correr `php artisan trash:purge --max-ratio=0.1` → assertea que aborta con log `trash.purge.aborted_mass_delete`.
- [x] 10.4 Verificar que `FileController@destroy` sigue devolviendo 200 (no 500) — sanity con `php artisan route:list`.
- [x] 10.5 Commit (mensaje tipo `feat(papelera): soft-trash, restore, cron purge` con body que liste los archivos). Stage SOLO los paths del change (no contaminar con los otros in-progress changes del working tree).
- [ ] 10.6 **PAUSA — deploy al servidor**: `git pull` + `php artisan migrate` + `composer dump-autoload` + `php artisan config:clear`. Requiere aprobación explícita del dueño del servidor.
- [ ] 10.7 Post-deploy 24h: monitorear `grep "trash.purge" app/storage/logs/laravel.log` + `grep "storage_sync.skipped_trashed_collision" app/storage/logs/laravel.log | wc -l` + el count de items en papelera (`SELECT COUNT(*) FROM files WHERE is_trashed=true;`).
