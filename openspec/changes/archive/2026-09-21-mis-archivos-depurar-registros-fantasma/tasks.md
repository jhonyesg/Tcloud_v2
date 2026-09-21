## 1. Snapshot pre-cambio

- [x] 1.1 Ejecutar `pg_dump --data-only --table=files --table=user_storages tcloudstorage | gzip > backups/mis-archivos-depura-pre-<ts>.sql.gz` para tener un respaldo completo antes de cualquier borrado.
- [x] 1.2 Anotar en `backups/CHANGELOG.md` el timestamp y el motivo ("Pre-apply de change mis-archivos-depurar-registros-fantasma").

## 2. Backend — modelo StorageProvider

- [x] 2.1 Añadir método estático `StorageProvider::personalCanonicalUsername(?self $sp): ?string` que devuelva el último segmento de `base_path` cuando `is_personal=true`, y `null` en caso contrario. Cubrir con tests unitarios (3 casos: personal, no personal, base_path sin segmento final).
- [x] 2.2 Añadir método de instancia `StorageProvider::isOwnedBy(User $user): bool` que use el helper anterior y `users.username` para resolver dueño canónico. Sin tocar `canonicalOwnerId` (ya retirado).

## 3. Backend — FileController (filtro de visibilidad)

- [x] 3.1 Modificar `FileController::storages()` (`app/app/Http/Controllers/FileController.php`, líneas 883-906): filtrar `$userStorages` para excluir storages con `is_personal=true` cuyo dueño canónico no es el usuario actual (admin bypass). Comportamiento idéntico al anterior para el resto.
- [x] 3.2 Verificar manualmente con sesión de `jsuarez`: el listado NO debe incluir storage 179 (`Personal - StakeholdersPrensa`).

## 4. Backend — comando `user-storages:fix-personal-visibility`

- [x] 4.1 Crear `app/app/Console/Commands/FixPersonalVisibilityCommand.php` con la firma `user-storages:fix-personal-visibility {--dry-run} {--apply} {--yes} {--user=}`. Estructura: `--dry-run` por defecto (sin flag, lista diff); `--apply` para ejecutar; `--user=` para limitar a un storage concreto; `--yes` para saltarse confirmación.
- [x] 4.2 Implementar el método `resolveCanonicalUsername(StorageProvider $sp): ?string` que use el helper del task 2.1.
- [x] 4.3 Implementar `audit()`: por cada storage personal con más de un `user_storages`, listar filas a conservar (`user_id` = canonical) y filas a eliminar (resto).
- [x] 4.4 Implementar `apply()` con borrado idempotente (chunks de 500) y `Log::info` por cada storage procesado.
- [x] 4.5 Implementar tabla de verificación final (conteo de `user_storages` por storage personal esperado ≤ 1).
- [x] 4.6 Dry-run en desarrollo: `cd app && php artisan user-storages:fix-personal-visibility` debe mostrar el diff del storage 179 sin tocar nada.

## 5. Backend — comando `files:purge-ghost-folders`

- [x] 5.1 Crear `app/app/Console/Commands/PurgeGhostFoldersCommand.php` con la firma `files:purge-ghost-folders {--dry-run} {--apply} {--yes} {--storage=}`.
- [x] 5.2 Implementar `findGhostCandidates(int $storageId = null): Collection` que ejecute la query: `is_folder=true AND parent_id IS NULL AND file_modified_at IS NULL AND NOT is_dir(base_path || '/' || path)` (en PHP, no en SQL — `is_dir()` se aplica en PHP fila por fila para evitar problemas de seguridad del path del contenedor PG).
- [x] 5.3 Implementar `dryRunReport()` que imprima agrupado por `storage_provider_id` con `id`, `name`, `path`, `created_at`, `count(files_ghost_pre_purge_*)` si existe.
- [x] 5.4 Implementar `apply()` que: (a) cree `CREATE TABLE files_ghost_pre_purge_<ts> AS SELECT * FROM files WHERE id IN (...)`; (b) ejecute `DELETE FROM files WHERE id IN (...)` en chunks de 500; (c) llame `StorageSyncService::invalidateFolderCache($storage->id, null)` por cada storage afectado; (d) imprima el conteo y el nombre de la tabla snapshot.
- [x] 5.5 Implementar verificación final: conteo de carpetas restantes con `file_modified_at IS NULL` por storage (debe ser 0 salvo error de snapshot).
- [x] 5.6 Dry-run en desarrollo: `cd app && php artisan files:purge-ghost-folders` debe listar las 29 candidatas del storage 5 sin tocar nada.

## 6. Pre-deploy: aplicar `--dry-run`

- [x] 6.1 `php artisan user-storages:fix-personal-visibility` → confirmar diff en pantalla: storage 179 pasa de 3 filas (Stakeholders, jsuarez, ausente StakeholdersPrensa) a 1 fila (resolución de canónico → warning si el dueño no existe).
- [x] 6.2 `php artisan files:purge-ghost-folders` → confirmar 29 candidatas en storage 5, 0 en otros storages.

## 7. Deploy

- [x] 7.1 `git add app/app/Console/Commands/PurgeGhostFoldersCommand.php app/app/Console/Commands/FixPersonalVisibilityCommandCommand.php app/app/Http/Controllers/FileController.php app/app/Models/StorageProvider.php`
- [x] 7.2 Commit con `git commit -m "feat(mis-archivos): comandos para depurar registros fantasma + filtro de visibilidad de personales"` (sin migración).
- [x] 7.3 `php artisan optimize:clear` (recarga opcode cache en PHP-FPM).

## 8. Apply-fix en producción

- [x] 8.1 `php artisan user-storages:fix-personal-visibility --apply --yes` y verificar tabla final (`user_storages` por personal ≤ 1).
- [x] 8.2 `php artisan files:purge-ghost-folders --apply --yes --storage=5` (primero solo storage 5; si hay más storages, repetir sin `--storage`).
- [x] 8.3 Confirmar manualmente que `jsuarez` ya NO ve `Personal - StakeholdersPrensa` en Mis Archivos (Chrome sesión del operador).
- [x] 8.4 Confirmar manualmente que al entrar a `00 Discos` el listado raíz muestra solo las carpetas reales (`Aplicaciones, Disco_A..Disco_I, dockers`).
- [x] 8.5 Botón Actualizar → corrige la IU si algo quedó; debe pasar limpio.

## 9. Documentación y limpieza

- [x] 9.1 En `AGENTS.md`, añadir una sección "Runbook: limpieza de datos corruptos en Mis Archivos" con los comandos `files:purge-ghost-folders` y `user-storages:fix-personal-visibility` y el patrón de snapshot.

### 10. Descubrimiento extra (2026-09-21): archivos huerfanos y desaparecidos

- [x] 10.1 Comando `files:purge-orphan-roots` para limpiar archivos con parent_id IS NULL en root de un storage (no carpetas). Clasifica: drop_dup_otro, drop_internal_dup, reassign, keep_root. Snapshot pre-DELETE.
- [x] 10.2 Aplicar `files:purge-orphan-roots --apply --yes --storage=5` → 3630 archivos borrados (574 dups_otro + 3056 dups_internos) + 2 reasignados. 213 keep_root quedan para revision.
- [x] 10.3 Comando `files:cleanup-disappeared` para borrar archivos cuyo path en BD no existe en disco (o cuyo padre directo no existe). Snapshot pre-DELETE.
- [x] 10.4 Aplicar `files:cleanup-disappeared --apply --yes --storage=5` → 5872 archivo_no_existe + 131471 padre_no_existe = 137343 archivos purgados de la BD (sin tocar disco).

## Resumen final del change

| Comando | Propósito | Total borrado |
|---|---|---|
| `user-storages:fix-personal-visibility` | user_storages personales con permisos cruzados | 2 |
| `files:purge-ghost-folders` | carpetas sin mtime cuyo path no esta en disco | 85 |
| `files:purge-orphan-roots` | archivos (no carpetas) con parent_id IS NULL | 3630 + 2 reasignados |
| `files:cleanup-disappeared` | archivos cuyo path no existe en disco | 137343 |
| **TOTAL** | | **141060** |
- [x] 9.3 Crear harness de regresión `tests/harness_purge_ghost_folders.php` que: (a) cree una carpeta fantasma en un storage de test, (b) corra `--apply` con tag de fixture, (c) verifique que se borró, (d) limpie con fixture tag.
