## 1. Fix #1 — PapeleraController::restore cache invalidation

- [x] 1.1 Localizar `PapeleraController::restore` (línea 95).
- [x] 1.2 Capturar `$originalStorageId = (int) $item->storage_provider_id;` ANTES del restore.
- [x] 1.3 Después del restore, capturar `$newParentId = $restored->parent_id;`.
- [x] 1.4 Llamar `app(\App\Services\StorageSyncService::class)->invalidateFolderCache($originalStorageId, $newParentId ?? null)` (null cuando el destino es root).

## 2. Fix #2 — Correcciones route whereNumber

- [x] 2.1 Localizar `Route::delete('/correcciones/{id}', [..., 'destroy']);` en `app/routes/web.php` línea 269.
- [x] 2.2 Agregar `->whereNumber('id')` al final de la línea.

## 3. Fix #3 — trash:purge --dry-run

- [x] 3.1 Modificar la `$signature` en `TrashPurgeCommand`: agregar `{--dry-run : cuenta candidatos sin borrar (no toca BD ni disco)}`.
- [x] 3.2 En `handle()`, leer `$dryRun = (bool) $this->option('dry-run');` y loggear `DRY-RUN` en el header.
- [x] 3.3 Pasar `$dryRun` como tercer argumento a `$service->purgeExpired($batch, $maxRatio, $dryRun)`.
- [x] 3.4 Cambiar el output final a `would_delete=N` cuando es dry-run.
- [x] 3.5 En `PapeleraService::purgeExpired`, agregar parámetro `bool $dryRun = false`.
- [x] 3.6 Dentro de `purgeExpired`, después del guardarrail de ratio y antes del `chunkById`, agregar `if ($dryRun) { Log::info('papelera.purge.dry_run', [...]); return $candidates; }`.

## 4. Verificación

- [x] 4.1 Playwright (`tests/playwright_restore_cache_invalidation.py`): login → trash un archivo → restaurar → recargar /files → archivo visible inmediatamente (no esperar TTL).
- [x] 4.2 Playwright: subfolder listing del parent de destino muestra el archivo restaurado INMEDIATAMENTE.
- [x] 4.3 CLI: `php artisan trash:purge --dry-run` → muestra `would_delete=0` sin tocar BD; output incluye `DRY-RUN`.
- [x] 4.4 CLI: `php artisan trash:purge` (sin flag) → muestra `deleted=0` como antes.
- [x] 4.5 curl: `DELETE /correcciones/abc` → 404 (route constraint), no 500 (TypeError).
- [x] 4.6 Regresión: `playwright_papelera_view.py` (11/11), `playwright_papelera_help_panel.py` (17/17), `playwright_papelera_cache_invalidation.py` (11/11), `playwright_filter_trashed_from_browser.py` (11/11), `playwright_restore_cache_invalidation.py` (5/5).

## 5. Archive

- [x] 5.1 Sync del spec delta a `openspec/specs/trash-module/spec.md`.
- [x] 5.2 `mv openspec/changes/fix-papelera-restore-cache-correcciones-route-purge-dryrun openspec/changes/archive/2026-09-07-fix-papelera-restore-cache-correcciones-route-purge-dryrun/`.
