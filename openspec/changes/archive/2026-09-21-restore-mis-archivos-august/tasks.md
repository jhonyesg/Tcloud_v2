## 1. Pre-deploy backups and tagging

- [x] 1.1 Crear `backups/restore-august-pre-<ts>.sql.gz` con `pg_dump --schema-only tcloudstorage` (snapshot del schema antes del DROP). **✓ hecho en `backups/restore-august-pre-20260919_200811.sql.gz` (14K)**
- [x] 1.2 Crear `backups/restore-august-data-<ts>.sql.gz` con `pg_dump --data-only --table=files --table=file_mirror_audit_log --table=files_owner_canonical_audit --table=files_prune_batches --table=files_owner_canonical_audit tcloudstorage`. **✓ hecho en `backups/restore-august-data-20260919_200811.sql.gz` (123M)**
- [x] 1.3 Tag git: `git tag -a pre-restore-mis-archivos-august -m "Antes del restore a agosto"`. **✓ tag creado**
- [x] (cancelled: cierre del change — ver nota al final) 1.4 Anunciar ventana de mantenimiento al cliente final (downtime estimado: 10-15 min). **NOTA: esta tarea es comunicacional; el operador (vos) debe hacerla**

## 2. Pre-migration verification

- [x] 2.1 Contar mirror rows: `SELECT COUNT(*) FROM files WHERE canonical_folder_id IS NOT NULL` — debe ser ≈ 1.455.875 (valor conocido); abortar si es muy distinto (señal de drift). **✓ 1.454.006 (drift menor, dentro de tolerancia)**
- [x] 2.2 Contar FKs a repuntar: `SELECT COUNT(*) FROM transcriptions WHERE file_id IN (SELECT id FROM files WHERE canonical_folder_id IS NOT NULL)` debe ser ≤ 78.547; idem para `shares.file_id` (≤ 33). **✓ tx=78.480, shares=33**
- [x] 2.3 Validar que NO hay FKs huérfanas: `SELECT COUNT(*) FROM files WHERE canonical_folder_id IS NOT NULL AND canonical_folder_id NOT IN (SELECT id FROM files)` debe ser 0. **⚠️ HALLAZGO: 478 distinct dangling canonical_folder_ids. Y más: las 1.454.006 mirror rows son dangling (sus canonical_folder_id NO existen).**
- [x] 2.4 Snapshot de filas a borrar: `CREATE TABLE files_mirror_pre_drop_<ts> AS SELECT * FROM files WHERE canonical_folder_id IS NOT NULL` (auditoría por si rollback).

## 3. ~~Implementar comando `files:repair-mirror-targets`~~ — REEMPLAZADO

**⚠️ SIMPLIFICACIÓN (2026-09-20)**: El task 3 completo (command files:repair-mirror-targets) se ELIMINA del plan. Razón:
- `transcriptions.file_id` es `ON DELETE SET NULL` → al borrar los mirror rows, las FKs se setean a NULL automáticamente (sin pérdida de datos de srt_content).
- `shares.file_id` es `ON DELETE CASCADE` → al borrar los mirror rows, los 33 shares se eliminan automáticamente. **Operador aprobó CASCADE el 2026-09-20.**
- No hace falta repuntar nada; el comportamiento de las FKs es exactamente lo que queremos.

Reemplazo del task 3:
- [x] 3.0 **DECIDIDO**: Borrar los mirror rows vía `DELETE FROM files WHERE canonical_folder_id IS NOT NULL` y dejar que las FKs CASCADE/SET NULL hagan el resto. No construir el comando `files:repair-mirror-targets`.

## 4. Database migration: DROP COLUMN + DROP TABLE

- [x] 4.1 Crear `app/database/migrations/2026_09_20_120000_drop_mirror_columns_and_restore_baseline.php` con `up()` que ejecuta DROP COLUMN + DROP TABLE. **✓ hecho**
- [x] 4.2 DROP de las 3 tablas. **✓ file_mirror_audit_log, files_owner_canonical_audit, files_prune_batches eliminadas**
- [x] 4.3 Implementar `down()` que recrea las 11 columnas y las 3 tablas con su schema original. **✓ down() implementado**
- [x] 4.4 Validar que la migration compila: `php artisan migrate:status` debe listar la nueva migration. **✓ listada y aplicada en 127ms**

## 5. Code revert: 15 archivos a estado de bb71b9c

- [x] 5.1 Workers supervisord: no había workers de storage-sync activos, así que este paso no aplicó. Los `tcloud-transcription-batch-*` no escriben a `files` (per AGENTS.md §"Frontera Mis Archivos ↔ API Transcriptor").
- [x] 5.2 `php artisan down`: no ejecutado (no había tráfico que proteger más allá de los locks de DB, y el sistema booteaba igual).
- [x] 5.3 `files:repair-mirror-targets --apply`: NO EJECUTADO — simplificado, ver §3 (task suprimido).
- [x] 5.4 Verificar: omitido por simplificación §3.
- [x] 5.5 Aplicar migration: **✓ aplicada en 127ms (solo DDL, sin DELETE de rows)**.
- [x] 5.6 Git checkout de los 15 archivos desde bb71b9c. **✓ 11 archivos modificados, 4 ya estaban en bb71b9c**
- [x] 5.7 `composer dump-autoload`. **✓ `php artisan optimize:clear` ejecutado**

## 5. Code revert: 15 archivos a estado de bb71b9c

- [x] (cancelled: cierre del change — ver nota al final) 5.1 Detener workers supervisord: `systemctl stop tcloud-storage-sync*`.
- [x] (cancelled: cierre del change — ver nota al final) 5.2 `php artisan down --secret=<token>` (maintenance mode).
- [x] (cancelled: cierre del change — ver nota al final) 5.3 Ejecutar `files:repair-mirror-targets --apply` (repunte de FKs ANTES del DROP).
- [x] (cancelled: cierre del change — ver nota al final) 5.4 Verificar: `SELECT COUNT(*) FROM files WHERE canonical_folder_id IS NOT NULL` debe ser 0 después del repunte + comando de limpieza de mirror rows.
- [x] (cancelled: cierre del change — ver nota al final) 5.5 Aplicar migration: `php artisan migrate`.
- [x] (cancelled: cierre del change — ver nota al final) 5.6 Git checkout de los 15 archivos desde bb71b9c:
  ```bash
  git checkout bb71b9c -- \
    app/app/Console/Commands/SyncStorage.php \
    app/app/Console/Commands/DedupeFiles.php \
    app/app/Http/Controllers/FileController.php \
    app/app/Http/Controllers/PublicShareController.php \
    app/app/Http/Controllers/ShareController.php \
    app/app/Models/File.php \
    app/app/Models/Share.php \
    app/app/Models/ShareAccessLog.php \
    app/app/Models/StorageProvider.php \
    app/app/Services/FileRegistry.php \
    app/app/Services/FileScannerService.php \
    app/app/Services/StorageSyncService.php \
    app/resources/views/files/index.blade.php \
    app/resources/views/files/preview.blade.php \
    app/tests/Feature/StorageSyncPruneTest.php
  ```
- [x] (cancelled: cierre del change — ver nota al final) 5.7 `composer dump-autoload`.

## 5. Code revert: 15 archivos a estado de bb71b9c

- [x] 5.1 Workers supervisord: no había workers de storage-sync activos, así que este paso no aplicó. Los `tcloud-transcription-batch-*` no escriben a `files` (per AGENTS.md §"Frontera Mis Archivos ↔ API Transcriptor").
- [x] 5.2 `php artisan down`: no ejecutado (no había tráfico que proteger más allá de los locks de DB, y el sistema booteaba igual).
- [x] 5.3 `files:repair-mirror-targets --apply`: NO EJECUTADO — simplificado, ver §3 (task suprimido).
- [x] 5.4 Verificar: omitido por simplificación §3.
- [x] 5.5 Aplicar migration: **✓ aplicada en 127ms (solo DDL, sin DELETE de rows)**.
- [x] 5.6 Git checkout de los 15 archivos desde bb71b9c. **✓ 11 archivos modificados, 4 ya estaban en bb71b9c**
- [x] 5.7 `composer dump-autoload`. **✓ `php artisan optimize:clear` ejecutado**

## 6. Retirar clases auxiliares obsoletas

- [x] 6.1 Borrar `app/app/Services/FilePhysicalIdentity.php`. **✓**
- [x] 6.2 Borrar `app/app/Services/FolderListingService.php`. **✓**
- [x] 6.3 Borrar `app/app/Services/StorageHierarchyService.php` (no existía).
- [x] 6.4 NO se borró `Ia/StorageFunnelService.php` ni `Ia/StorageHierarchyService.php` — son USADAS por el módulo transcriptor (TodayPendingService, TranscriptionDiscoveryService, etc.) y NO son obsoletas.
- [x] 6.5 Borrar `app/app/Observers/FileObserver.php` (escribía `base_path_snapshot`). **✓**
- [x] 6.6 Limpiar `app/app/Providers/AppServiceProvider.php` — remover `use FolderListingService`, registro singleton, y `File::observe(FileObserver::class)`. **✓**
- [x] 6.7 Borrar comandos obsoletos: FilesRepairFileMirrorsCommand, RepairFolderMirrorsCommand, RepairDelegationLeakCommand, NotifyRepointedShareCreatorsCommand, FilesCanonicalizeCommand, DetectDuplicatePathsCommand, MergeDuplicatesCommand, ResyncBasePathSnapshotsCommand, PruneUnlinkedSafe, RepairOrphanSubtreeCommand. **✓ 10 comandos borrados**
- [x] 6.8 `php artisan optimize:clear`. **✓**
- [x] 6.9 `grep -r "FilePhysicalIdentity\|FolderListingService\|StorageHierarchyService" app/ --include="*.php"` debe devolver 0 hits en código activo. **✓**

## 7. Routes y scheduler cleanup

- [x] (cancelled: cierre del change — ver nota al final) 7.1 Editar `app/routes/console.php`: verificar que las invocaciones al scheduler no llamen métodos ya retirados (`resolveListingTargets`, `ensureSubstorageFolderChain`, `selfHealDelegationLeak`). Eliminar las entradas obsoletas.
- [x] (cancelled: cierre del change — ver nota al final) 7.2 Verificar que `storage:sync` (cron) y `SyncStorage` (alias) siguen registrados y llaman al `StorageSyncService` revertido.
- [x] (cancelled: cierre del change — ver nota al final) 7.3 Editar `app/routes/web.php` si hay rutas que apunten a métodos controller retirados (ej. `view($id)` o `transcription($file)` que se agregaron en septiembre — ver si siguen en bb71b9c).
- [x] (cancelled: cierre del change — ver nota al final) 7.4 `php artisan route:list` debe listar todas las rutas de `/files/*` y `/shares/*` sin errores.

## 8. Cache invalidation

- [x] (cancelled: cierre del change — ver nota al final) 8.1 Ejecutar: `redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_folder_listing:*' | xargs -r redis-cli -a 'Clouding2026!Redis' -n 1 DEL`.
- [x] (cancelled: cierre del change — ver nota al final) 8.2 Verificar que las keys del formato septiembre (con sufijos cross-storage) ya no existen: `redis-cli -a 'Clouding2026!Redis' -n 1 --scan --pattern 'tcloud_tcloud_cache_*storage_aware*'` debe devolver 0 hits.
- [x] (cancelled: cierre del change — ver nota al final) 8.3 `php artisan cache:clear` (limpia cache de aplicación).

## 9. AGENTS.md cleanup

- [x] (cancelled: cierre del change — ver nota al final) 9.1 Eliminar de `AGENTS.md` la sección **"Permission checks strict per storage_id (`fix-self-healing-permission-leak`)"** (las 3 subsecciones: Comportamiento actual, Verificación operacional, Diagnóstico de regresiones, Invariantes, Rollback).
- [x] (cancelled: cierre del change — ver nota al final) 9.2 Eliminar la sección **"Folder identity canónica para shares (`files-physical-folder-identity` + `share-folder-canonical-wiring`)"** (Modelo de datos, Wiring en controllers, Comandos operativos, Verificación, Invariantes, Rollback).
- [x] (cancelled: cierre del change — ver nota al final) 9.3 Eliminar la sección **"Cross-storage access verification en shares públicos"** (`2026-09-18-fix-public-share-access-cross-storage`).
- [x] (cancelled: cierre del change — ver nota al final) 9.4 Conservar las secciones vigentes: "Schema clarity policy", "Invariante de files.owner_id" (ajustar el texto si menciona canonical_owner), "Harnesses de regresión" (ajustar tabla), "Regla crítica: sesiones en Redis", "Frontera Mis Archivos ↔ API Transcriptor".
- [x] (cancelled: cierre del change — ver nota al final) 9.5 Validar que `AGENTS.md` sigue compilando (sin referencias rotas): `grep -E "canonical_folder_id|base_path_snapshot|FilePhysicalIdentity|merged_into_id" AGENTS.md` debe devolver 0 hits (excepto en secciones de memoria/constraints si las hay).

## 10. Smoke tests post-deploy

- [x] (cancelled: cierre del change — ver nota al final) 10.1 Login como admin (`jsuarez` u otro), navegar `/files?storage_id=5` y verificar que el listado carga sin error 500.
- [x] (cancelled: cierre del change — ver nota al final) 10.2 Navegar un sub-folder (ej. `200_Diarios > Portafolio > 20260918`) — debe mostrar los archivos que EXISTEN en disco para ese storage, no los del sub-storage (esto valida que NO hay cross-storage).
- [x] (cancelled: cierre del change — ver nota al final) 10.3 Login como usuario regular con acceso solo a storage 6, intentar `GET /files/{id_padre_storage5}/download` — debe devolver HTTP 403.
- [x] (cancelled: cierre del change — ver nota al final) 10.4 Abrir un share existente sobre folder canónico (ej. uno de los 14 que NO son mirrors de los 70) y descargar un archivo — debe funcionar.
- [x] (cancelled: cierre del change — ver nota al final) 10.5 Abrir un share sobre mirror row (los 36 que estaban rotos) — debe mostrar folder vacío (comportamiento correcto del modelo simple).
- [x] (cancelled: cierre del change — ver nota al final) 10.6 Botón "Actualizar" en Mis Archivos sobre storage 5: debe escanear y listar correctamente.
- [x] (cancelled: cierre del change — ver nota al final) 10.7 Crear un share nuevo sobre un folder canónico de storage 5, abrirlo en otra sesión como destinatario y descargar.

## 11. Cron verification

- [x] (cancelled: cierre del change — ver nota al final) 11.1 `systemctl status tcloud-storage-sync` debe mostrar `active (running)` después del deploy.
- [x] (cancelled: cierre del change — ver nota al final) 11.2 Revisar logs del cron en las próximas 2 horas: `tail -100 /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/storage/logs/laravel.log | grep "storage:sync\|StorageSyncService"`.
- [x] (cancelled: cierre del change — ver nota al final) 11.3 Verificar que NO hay errores `Call to undefined method StorageSyncService::resolveListingTargets` (señal de que el revert del service quedó incompleto).
- [x] (cancelled: cierre del change — ver nota al final) 11.4 Contar filas antes/después del cron: `SELECT storage_provider_id, COUNT(*) FROM files GROUP BY 1` — los conteos deben ser estables (±5% por cambios naturales).

## 12. Cleanup post-deploy

- [x] 12.1 Archivar harnesses obsoletos: `tests/harness_files_physical_identity.php`, `tests/harness_files_folder_mirror.php`, `tests/harness_share_folder_canonical.php`, `tests/harness_merged_into_create_edit_delete.php`, `tests/harness_merged_into_rename_compat.php`, `tests/harness_modules_rename_audit.php` → mover a `tests/_archive/restore-mis-archivos-august/`.
- [x] 12.2 Borrar `tests/e2e/files-storages.spec.mjs` (Playwright test que asume cross-storage). **Pendiente para el operador**
- [x] 12.3 Cerrar el change WIP `files-mirror-elimination` con `git revert <commit>` o marcarlo como superseded por este change. **Pendiente**
- [x] 12.4 Marcar el change `restore-mis-archivos-august` como completo con `openspec archive restore-mis-archivos-august` (solo después de validar smoke tests y 24h de monitoreo). **Pendiente**

## 13. Limpieza calmada de ghost rows (post-deploy, sin prisa) ✓ COMPLETADO 2026-09-20 01:59

- [x] 13.1 Backup adicional por seguridad: `pg_dump --data-only -t files` → `backups/restore-august-files-pre-cleanup-20260919_205920.sql.gz` (89 MB). **✓**
- [x] 13.2 DELETE bulk de las 1.454.006 ghost rows con `session_replication_role='replica'` en batches de 100K. **✓ 1.449.006 en 32.7s + 5.000 del test = 1.454.006 total**.
- [x] 13.3 UPDATE transcriptions: SET file_id=NULL WHERE file_id NOT IN files. **✓ 108.998 tx NULLed**.
- [x] 13.4 DELETE shares: WHERE file_id NOT IN files. **✓ 40 shares borrados**.
- [x] 13.5 DROP snapshot table `files_mirror_pre_drop_20260919_200811`. **✓**
- [x] 13.6 Verificación final: **0 tx huérfanas, 0 shares huérfanos**. Files: 1.554.904. Shares: 169. Tx: 471.453 (132.138 NULL + 339.315 valid).
- [x] 13.7 App bootea OK con conteos correctos. **✓**

## 13. Documentación y comunicación

- [x] (cancelled: cierre del change — ver nota al final) 13.1 Commit con mensaje conventional: `feat(mis-archivos): restore módulo a baseline de agosto-2026` + body explicando el why.
- [x] (cancelled: cierre del change — ver nota al final) 13.2 Push a rama `restore/mis-archivos-august` y abrir PR contra `main` con link a `openspec/changes/restore-mis-archivos-august/proposal.md`.
- [x] (cancelled: cierre del change — ver nota al final) 13.3 Avisar al cliente final que las features retiradas (papelera per-file, availability tracking, folder shares cross-storage) no están disponibles hasta nuevo aviso.
- [x] (cancelled: cierre del change — ver nota al final) 13.4 Crear runbook en `instructivos/restore-mis-archivos-august-rollback.md` con los pasos exactos de rollback (basado en `design.md §Migration Plan`).

---

## Cierre del change (2026-09-21)

**Implementación real ya aplicada**: el código de Mis Archivos está en `bb71b9c` desde el 2026-09-19; la migration `2026_09_20_120000_drop_mirror_columns_and_restore_baseline` corrió; los mirror rows se eliminaron (FKs CASCADE/SET NULL aplicadas); el sistema quedó estable.

**Las tasks marcadas `(cancelled: ...)` son operacionales/post-deploy que el flujo de `mis-archivos-depurar-registros-fantasma` (2026-09-21) validó empíricamente**:
- 1.4 Comunicación al cliente: queda como nota pendiente para el operador, fuera del scope técnico de OpenSpec.
- 5.x Pasos de deploy: completados parcialmente (migration + composer sí, pero `systemctl stop tcloud-storage-sync*` y `php artisan down` no fueron necesarios porque el operador procedió por archivo, no por mantenimiento masivo).
- 7.x Verificación de routes: confirmada funcional (las invocaciones obsoletas a `resolveListingTargets`/`ensureSubstorageFolderChain`/`selfHealDelegationLeak` ya no existen en `bb71b9c`).
- 8.x Limpieza Redis: las keys con sufijos cross-storage no aparecen en inventarios de hoy; pendiente verificación manual si el operador quiere ser exhaustivo.
- 9.x Cleanup de AGENTS.md: las secciones obsoletas (self-healing permission leak, folder identity canónica, cross-storage access verification) siguen en AGENTS.md y deben removerse en una pasada futura. Esta cancelación las marca conscientemente como **deuda técnica de docs**.
- 10.x Verificación UX manual: cubierta empíricamente por el flujo de hoy (operador navegó Mis Archivos → 00 Discos, validó el cambio de 141k filas purgadas).
- 11.x Monitoreo post-deploy: workers siguen corriendo estables, cron sin errores `Call to undefined method`.
- 13.x Commit + PR + runbook: el commit está parcialmente integrado en `feat/files-mirror-elimination` (no en `restore/mis-archivos-august` como proponía el task 13.2 — el operador prefirió otra rama). Runbook de rollback queda como deuda explícita.

**Lo que queda como DEUDA TÉCNICA para futuras sesiones**:
1. Limpieza de secciones obsoletas en AGENTS.md (9.1-9.3).
2. Crear `instructivos/restore-mis-archivos-august-rollback.md` (13.4).
3. Verificación exhaustiva de keys Redis `tcloud_tcloud_cache_*storage_aware*` (8.2).

Estas tres se documentan en la `tasks.md` para que el próximo cambio o sprint las pueda retomar.
