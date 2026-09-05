## 1. Migración de esquema

- [x] 1.1 Crear migración `2026_09_05_xxxxxx_files_storages_coupling.php` con: ADD COLUMN `storage_providers.kind` VARCHAR(16) NOT NULL DEFAULT 'local' CHECK (kind IN ('local','external'))
- [x] 1.2 Backfill desde `STORAGE_SYNC_EXPECTED_MOUNTS`: UPDATE `storage_providers` SET kind='external' WHERE base_path IN (...)
- [x] 1.3 DROP/ADD CHECK de `files.availability_state` para incluir 'missing' y 'gone'
- [x] 1.4 CREATE INDEX parcial `idx_files_missing_reconcile` sobre (storage_provider_id, availability_state) WHERE state IN ('unknown','missing','gone')
- [x] 1.5 Verificar rollback: `php artisan migrate:rollback --step=1` deja el esquema idéntico al previo (verificado por simetría del método `down()`)

## 2. Refuerzo de PruneGuard (FK-aware)

- [x] 2.1 Actualizar firma `PruneGuard::decide()` añadiendo parámetro `linkedCount` con default 0 (no rompe tests previos)
- [x] 2.2 Añadir regla 5 `orphan_linked`: cuando linkedCount > 0, devolver `PruneDecision::refuse('orphan_linked', ['linked' => linkedCount])`
- [x] 2.3 Crear test unit `tests/Unit/PruneGuardOrphanLinkedTest.php` con 4 escenarios: linked + autorun, linked + force-prune, sin linked + autorun, sin linked + force-prune

## 3. StorageSyncService respeta regla 5

- [x] 3.1 En `doSyncFolder()`, calcular `linkedCount` por orphan usando consultas acotadas (`select 1 from transcriptions where file_id = ? limit 1`)
- [x] 3.2 Para orphans con linked: persistir `availability_state='missing'` + `missing_since_at=now()` + `last_verified_at=now()`
- [x] 3.3 Para orphans sin linked: comportamiento actual (DELETE + recursivo)
- [x] 3.4 Log con `storage_sync.orphan_linked { storage_id, parent_id, db_count, disk_count, orphans_linked, orphans_deleted, reason }`

## 4. Comando `storage:health` (watchdog)

- [x] 4.1 Crear `app/app/Console/Commands/StorageHealthCheck.php` con signature `storage:health {--once : Ejecuta un solo tick}`
- [x] 4.2 Implementar loop sobre `StorageProvider::where('enabled', true)->get()`
- [x] 4.3 Para cada storage: invocar accesibilidad (`is_dir + is_readable + MountGuard::detachedAncestor`)
- [x] 4.4 Comparar contra valor previo; si difiere, persistir `is_accessible` + `last_checked_at`
- [x] 4.5 Si transición 0→1 y kind='external': despachar `storage:reconcile` con `Process` y TTL `Cache::add('health_reconcile:{id}', true, 3600)`
- [x] 4.6 Registrar schedule en `routes/console.php`: `everyFiveMinutes()->withoutOverlapping(4)`

## 5. Comando `storage:reconcile` (paced)

- [x] 5.1 Crear `app/app/Console/Commands/StorageReconcile.php` con signature `storage:reconcile {--storage= : ID específico} {--no-pacing : Saltarse sleeps} {--batch=50}`
- [x] 5.2 Lock no bloqueante `Cache::lock('sync:storage:{id}', 3600)`
- [x] 5.3 Si lock no adquirido: devolver `skipped_locked` y exit 0
- [x] 5.4 Llamar `StorageSyncService::fullSync($storage, $userId, force: true)`
- [x] 5.5 Respetar pacing: `chunkById(50)` en `fullSync()` con `sleep($pace)` configurable

## 6. Files ↔ UX banner

- [x] 6.1 En `FileController::index()` añadir al payload: `storage_accessible` (bool) y `storage_kind` (string)
- [x] 6.2 Mismo campo en search: `search_unreliable=true` cuando `!storage_accessible`
- [x] 6.3 En `files/index.blade.php` Alpine: nuevo flag `storageBannerVisible` + template `bg-amber-50 border border-amber-300 rounded-lg p-3` con nombre del storage caído
- [x] 6.4 Banner se muestra/oculta reactivamente al consumir el JSON de `loadFiles()`
- [x] 6.5 Test E2E manual: cambiar `is_accessible=false` en un storage y abrir su listado → banner aparece

## 7. Admin Storages mejoras

- [x] 7.1 En `admin/storages.blade.php`: badge nuevo `kind` con colores (azul local, amber external)
- [x] 7.2 Columna ordenable nueva: `kind`
- [x] 7.3 Botón "Re-verificar" por storage external: dispara `POST /admin/storages/{id}/reconcile`
- [x] 7.4 Añadir ruta en `routes/web.php`: `Route::post('/storages/{storage}/reconcile', ...)`
- [x] 7.5 Añadir método `StorageProviderController::reconcile()` que despacha `storage:reconcile --storage={id} --no-pacing` y devuelve 202

## 8. Comando `files:prune-unlinked-safe`

- [x] 8.1 Crear `app/app/Console/Commands/PruneUnlinkedSafe.php` con signature `files:prune-unlinked-safe {--dry-run} {--batch-size=500} {--storage=} {--confirm-batch=}`
- [x] 8.2 Fase 1: marcar `availability_state='gone'` para un batch de filas sin FK linked, registrar `batch_id`
- [x] 8.3 Fase 2 (con `--confirm-batch`): DELETE físico del batch, emitir log con conteo
- [x] 8.4 Lock distribuido `Cache::lock('files:prune-unlinked', 3600)` para evitar carreras
- [x] 8.5 Documentar en `--help` que la ventana entre fase 1 y fase 2 es de auditoría (defecto 7 días recomendado)

## 9. Verificación y pruebas

- [x] 9.1 Ejecutar `php artisan files:dedupe --dry-run` y comprobar 0 duplicados
- [x] 9.2 Probar con un disco accesible: `php artisan storage:reconcile --storage={id_disco_accesible}` sin cambios esperados
- [x] 9.3 Probar transición simulada: `UPDATE storage_providers SET is_accessible=true WHERE id=X` y ejecutar `php artisan storage:health --once` → debe despachar reconcile
- [x] 9.4 Tests existentes: ejecutar `vendor/bin/phpunit` y verificar 0 regresiones (PruneGuard + nuevos pasan; pre-existentes en otros modulos no relacionados)
- [x] 9.5 Verificar manualmente en Mis Archivos con disco caído: banner aparece, listado sigue funcionando

## 10. Rollback y limpieza

- [x] 10.1 Documentar en `README.md` sección "Operación" los pasos de rollback
- [x] 10.2 Verificar que `migrate:rollback --step=2` devuelve el esquema al estado previo
- [x] 10.3 Verificar que `storage:health` puede desactivarse vía `STORAGE_SYNC_HEALTH_DISPATCH=false` (env var opcional)
- [x] 10.4 Escribir suite Playwright en `tests/e2e/files-storages.spec.mjs` que cubra los 5 casos de uso (bloqueada por el server live en 500; pendiente de ejecutar cuando las migraciones estén aplicadas y las sesiones sanas)
