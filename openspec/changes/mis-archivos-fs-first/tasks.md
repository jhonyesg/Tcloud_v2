# Tasks

## 1. Benchmarking y validación de hipótesis

- [ ] 1.1 Crear `app/app/Console/Commands/MisArchivosBenchmarkCommand.php` con signature `mis-archivos:benchmark {--storage=*} {--samples=20} {--depth=4}`.
- [ ] 1.2 El comando mide, para cada carpeta muestreada en cada storage: latencia de `scandir() + stat()` vs latencia de query BD equivalente. Reporta min/avg/max/p95.
- [ ] 1.3 Ejecutar contra storage 134 (`04 Emisoras 03`), 6 (`01 Caracol Tv`) y 5 (`00 Discos`). Capturar salida como baseline en `openspec/changes/mis-archivos-fs-first/benchmark-baseline.txt`.
- [ ] 1.4 Si latencia FS > 500 ms en > 20% de las carpetas, ajustar el plan (paginación client-side obligatoria desde el día 1).

## 2. Servicio de listado desde filesystem

- [ ] 2.1 Crear `app/app/Services/MisArchivos/FilesystemListingService.php` con namespace `App\Services\MisArchivos`.
- [ ] 2.2 Implementar `list(int $storageId, ?string $subPath, int $userId, int $limit = 500): array` con detección de `path_missing`, `mount_detached`, `path_outside_base` y respuesta con shape `{files, pagination, breadcrumbs, meta, error?}`.
- [ ] 2.3 Implementar `parentChain(int $storageId, ?string $subPath): array` basado en `explode('/', trim($subPath, '/'))`. Cada segmento incluye `name`, `path`, `has_file_id`, `storage_provider_id`.
- [ ] 2.4 Implementar `resolveFileId(int $storageId, string $path): ?int` con cache `mis_archivos:path_lookup:{storageId}:{md5(path)}` TTL 60s configurable vía `SystemSetting('mis_archivos.path_cache_ttl')`.
- [ ] 2.5 Implementar `entryToArray()` privado que devuelve shape compatible con la respuesta AJAX actual + campos `has_file_id`, `source`, `permissions`, `actions`.
- [ ] 2.6 Toda llamada a `stat()`, `scandir()`, `is_dir()`, `is_readable()` DEBE usar `@` para silenciar warnings de NFS en estado degradado. Loguear con `Log::warning('mis_archivos.fs_io_error', ...)` cuando falle.
- [ ] 2.7 Tests unitarios en `tests/Unit/FilesystemListingServiceTest.php` con 8+ casos (path válido, path missing, mount detached, NFS timeout, archivo sin file_id, archivo con file_id, permisos insuficientes, límite de paginación).

## 3. Guard de permisos

- [ ] 3.1 Crear `app/app/Services/MisArchivos/FilesystemPermissionGuard.php` con métodos `filter(array $entries, int $userId): array`, `canAccess(int $storageId, int $userId, string $permission = 'read'): bool`.
- [ ] 3.2 `filter()` consulta `user_storages` una vez, cachea en request scope. Admin bypass total.
- [ ] 3.3 `canAccess()` delega a `User::hasStoragePermission()` que ya existe. Tests verifican que cliente sin acceso no ve nombres de archivos.

## 4. Matcher filesystem ↔ BD

- [ ] 4.1 Crear `app/app/Services/MisArchivos/FilesystemDbMatcher.php` con métodos `matchStorage(int $storageId, string $mode = 'hot_warm'): array` y helpers privados `matchOne()`, `markMissing()`, `matchRecursive()`.
- [ ] 4.2 Modos: `hot_only`, `hot_warm`, `hot_warm_cold`. Default desde `SystemSetting('mis_archivos.matcher_mode', 'hot_warm')`.
- [ ] 4.3 Modo hot: lee `Cache::get("mis_archivos:recent_paths:{storageId}")` (paths tocados por uploads/downloads en últimas 2h).
- [ ] 4.4 Modo warm: scandir recursivo hasta nivel 3, ignorando dotfiles y `node_modules`.
- [ ] 4.5 Modo cold: `DB::table('files')->where('storage_provider_id', $storageId)->whereNull('pending_deletion_at')->chunkById(500, ...)` para encontrar filas en BD sin contraparte en disco.
- [ ] 4.6 Toda fila en BD sin contraparte en disco se marca con `pending_deletion_at = now()`. Si han pasado más de `SystemSetting('mis_archivos.missing_grace_days', 7)` días, se borra (CASCADE borra shares, SET NULL en `transcriptions.file_id`).
- [ ] 4.7 Tests unitarios en `tests/Unit/FilesystemDbMatcherTest.php` con 6+ casos (match existente, crear nuevo, marcar missing, purgar tras gracia, idempotencia, modo hot sin recent paths).

## 5. Comando y job

- [ ] 5.1 Crear `app/app/Console/Commands/MatchFilesystemToDatabaseCommand.php` con signature `mis-archivos:match-fs-db {--storage=*} {--mode=hot_warm_cold}`.
- [ ] 5.2 El comando respeta lock distribuido `sync:matcher:storage:{id}` (TTL 600 s). Si otro proceso corre, retorna `skipped_locked` y termina.
- [ ] 5.3 Crear `app/app/Jobs/MatchFilesystemToDatabaseJob.php` para invocación desde queue (cuando se quiera ejecutar async).
- [ ] 5.4 Registrar schedule en `routes/console.php`: `Schedule::command('mis-archivos:match-fs-db --mode=hot_warm')->everyFiveMinutes()->withoutOverlapping(4)` y `Schedule::command('mis-archivos:match-fs-db --mode=hot_warm_cold')->dailyAt('03:00')->withoutOverlapping(120)`.

## 6. Feature flag y canary

- [ ] 6.1 Settings nuevos (todos via `SystemSetting`):
  - `mis_archivos.fs_primary_enabled` (default `false`)
  - `mis_archivos.fs_primary_canary_storage_ids` (default `[]`, JSON-encoded)
  - `mis_archivos.listing_limit` (default `500`)
  - `mis_archivos.path_cache_ttl` (default `60`)
  - `mis_archivos.missing_grace_days` (default `7`)
  - `mis_archivos.matcher_mode` (default `hot_warm`)
  - `mis_archivos.matcher_rows_per_minute` (default `2000`, rate-limit opcional)
- [ ] 6.2 Helper `MisArchivos::fsPrimaryEnabled(int $storageId): bool` que combina los settings para decidir routing.
- [ ] 6.3 Tests verifican que con `enabled=false` el código viejo corre intacto.

## 7. Modificación a FileController

- [ ] 7.1 En `FileController::index` (~línea 39), bifurcar: si `MisArchivos::fsPrimaryEnabled($storageId)` → invoca `FilesystemListingService::list()`, sino → comportamiento actual.
- [ ] 7.2 En `FileController::search` (ruta de búsqueda), cuando FS-primario activo: usar `shell_exec('find ' . escapeshellarg($absolute) . ' -maxdepth 4 -iname ' . escapeshellarg('*' . $term . '*') . ' -printf "%p\n"')` con timeout 2s y fallback a BD query si timeout.
- [ ] 7.3 La respuesta AJAX incluye campo `meta.fs_primary: bool` para que la UI pinte badge "FS" o "BD".
- [ ] 7.4 Tests E2E con feature flag en cada estado verifican que el shape de respuesta es compatible.

## 8. Modificación a ShareController

- [ ] 8.1 En `ShareController::download()`, si `$share->file_id` es null, intentar resolver por `(storage_id, path_snapshot)` vía `FilesystemListingService::resolveFileId()`. Si tampoco, abortar 404 con mensaje claro.
- [ ] 8.2 Al crear share nuevo (`ShareController::store`), intentar resolver `file_id` antes de guardar. Si no existe, guardar con `file_id=null` y `path_snapshot = $request->input('path')` (columna nueva).
- [ ] 8.3 Migration `2026_09_29_000001_add_path_snapshot_to_shares.php` agrega `path_snapshot VARCHAR(500) NULL` a `shares`. Reversible.

## 9. Vista Mis Archivos

- [ ] 9.1 En `resources/views/files/index.blade.php`, pintar badge "FS" (verde) o "BD" (gris) en la esquina superior derecha del listado cuando `meta.fs_primary` esté presente.
- [ ] 9.2 Si la respuesta trae `meta.matcher_pending > 0`, mostrar toast info "X archivos pendientes de emparejar con la base de datos".
- [ ] 9.3 Si `error === 'mount_detached'` o `'path_missing'`, mostrar overlay en la carpeta con icono de disco desconectado y mensaje "Disco no disponible — reintentaremos cuando vuelva".
- [ ] 9.4 NO mostrar el toast warning actual ("No se pudo actualizar: ...") cuando FS-primario está activo: el sistema ya reporta el error honestamente sin estados fantasma.

## 10. Migration

- [ ] 10.1 `2026_09_29_000000_add_pending_deletion_at_to_files.php`: agregar `pending_deletion_at TIMESTAMPTZ NULL` + índice `(storage_provider_id, pending_deletion_at)`.
- [ ] 10.2 `2026_09_29_000001_add_path_snapshot_to_shares.php`: agregar `path_snapshot VARCHAR(500) NULL` a `shares`.
- [ ] 10.3 NO modificar otras columnas. `parent_id`, `is_folder`, `storage_provider_id`, etc. quedan intactos.

## 11. Comando admin para diagnóstico

- [ ] 11.1 `php artisan mis-archivos:fs-drift-report --storage=134`: lista paths que están en BD pero no en disco (con `pending_deletion_at` vencido) y paths que están en disco pero no en BD (candidatos a match).
- [ ] 11.2 El comando NO muta nada. Solo reporta. Útil para validación post-fase 2.

## 12. Verificación

- [ ] 12.1 Harness nuevo `tests/harness_mis_archivos_fs_first.php` con tag `hfs_<hex>`. Cubre: scandir vs BD parity, matcher idempotencia, permission guard, path resolve cache, mount_detached handling.
- [ ] 12.2 Limpieza defensiva al inicio con `WHERE path LIKE 'hfs_%'`.
- [ ] 12.3 Benchmark command (tarea 1) ejecutado y resultados guardados como baseline.
- [ ] 12.4 Canary storage 134 activado durante 48h, logs `mis_archivos.listing_error` revisados, latency p95 medida.

## 13. Rollback

- [ ] 13.1 `SystemSetting::set('mis_archivos.fs_primary_enabled', '0')` revierte a modo BD-primero sin deploy.
- [ ] 13.2 Las migrations son forward-reversible (down() restaura). `php artisan migrate:rollback --step=2` en emergencia.
- [ ] 13.3 `git revert` del commit correspondiente deja el código idéntico al baseline `39961b3`.
- [ ] 13.4 Los servicios `FilesystemListingService` y `FilesystemDbMatcher` siguen existiendo en código (no se borran) por si se reactiva el feature.
