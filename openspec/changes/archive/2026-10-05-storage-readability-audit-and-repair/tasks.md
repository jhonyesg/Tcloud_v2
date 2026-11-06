## 1. Setup

- [x] 1.1 Confirmar baseline de permisos actuales con `php artisan storage:audit-ownership --storage=55` y capturar los 8 untraversable como fixture esperado.
- [x] 1.2 Verificar que `posix_geteuid()` está disponible en PHP-FPM (`php -m | grep posix`).
- [x] 1.3 Verificar que el grupo `storage-admins` existe en el sistema (`getent group storage-admins`) y que `www` es miembro.

## 2. Detección de untraversable (backend)

- [x] 2.1 Implementar helper `StorageOwnershipProbe::detectUntravsersable(StorageProvider $storage): array` en `app/app/Services/StorageOwnershipProbe.php` que ejecuta `scandir` + `@opendir` por hijo directo del `base_path` y cruza con `files` para confirmar contenido en BD.
- [x] 2.2 Añadir denylist invariante en `StorageOwnershipProbe::DENYLIST = ['System Volume Information', '$RECYCLE.BIN', '.Recycle_bin']` más filtro `not str_starts_with('.')`.
- [x] 2.3 Extender `StorageOwnershipAuditCommand::handle()` con fase nueva después del `find` existente: invoca el helper, acumula por storage, persiste en `SystemSetting('storage_health:<id>:untraversable_count')`.
- [x] 2.4 Añadir `--scope=untraversable|all` (default `all`) para que el operador pueda correr solo la fase nueva sin duplicar el reporte de `owner_mismatch`.
- [x] 2.5 Extender el método `report()` del comando con bloque nuevo en la salida: `=== HALLAZGOS ===  owner_mismatch: N  untraversable: N  metadata_skip: N`.
- [x] 2.6 Añadir logging estructurado: `Log::warning('storage.ownership_audit.untraversable_detected', [...])` por storage con `untraversable > 0`.
- [x] 2.7 Implementar persistencia de `SystemSetting('storage_health:audit_finished_at')` solo en corridas exitosas (no cuando saltó por lock).
- [x] 2.8 Smoke test: `php artisan storage:audit-ownership --storage=55` debe listar los 8 untraversable ya conocidos y NO listar `System Volume Information` ni `$RECYCLE.BIN`.

## 3. Repair command (backend)

- [x] 3.1 Crear `app/app/Console/Commands/StorageRepairOwnershipCommand.php` con signature `storage:repair-ownership {--storage= : ID} {--limit=10} {--apply} {--yes} {--drop-old-snapshots=0}`.
- [x] 3.2 Implementar `posix_geteuid() !== 0` check al inicio: abort con exit code 1 + mensaje claro si no es root.
- [x] 3.3 Implementar carga de paths desde `SystemSetting('storage_health:<id>:untraversable_paths')` (key nueva escrita por el audit; o derivar re-corriendo el probe en el repair).
- [x] 3.4 Aplicar denylist al set de paths; abortar con mensaje si 0 paths sobreviven al filtro.
- [x] 3.5 Implementar confirmación interactiva: si `--apply && !--yes && paths > 5`, leer de STDIN `y/N`; abortar limpio en cualquier otra respuesta.
- [x] 3.6 Implementar `--dry-run` (default) que imprime `path mode owner group bd_count` sin mutar.
- [x] 3.7 Implementar `--apply`: crear tabla snapshot `files_ownership_pre_repair_<YYYYMMDD_HHMMSS>`, INSERT SELECT WHERE storage_provider_id=X AND path LIKE '<path>/%', ejecutar `Process::run('chown -R www:storage-admins ' . escapeshellarg($path))` + `Process::run('chmod -R u+rwX,g+rwX ' . escapeshellarg($path))` por path.
- [x] 3.8 Implementar `--drop-old-snapshots=N` que lista y dropea `files_ownership_pre_repair_*` con timestamp anterior a N días.
- [x] 3.9 Smoke test con UID 0: `php artisan storage:repair-ownership --storage=55` (dry-run) debe listar 8 paths y NO mutar.
- [x] 3.10 Smoke test con UID 0 y `--apply --yes`: requiere NFS sin root_squash o chown desde el NFS server. Para storage 55 (Disco_H, NFS4 root_squash) el comando falla con "Permission denied"; documentado en design.md y AGENTS.md. Verificar con storage local (Disco_A/C/I) en su lugar.
- [x] 3.11 Verificar que la tabla snapshot existe con el conteo correcto de filas.
- [x] 3.12 Re-correr audit post-repair: debe reportar `untraversable: 0` para el storage reparado.

## 4. Toast diferenciado (frontend)

- [x] 4.1 Editar `app/app/Services/StorageSyncService.php::doSyncFolder()`: añadir rama `is_readable($realPath) === false` DESPUÉS del check existente de `is_dir`, retornar `'path_unreadable'`.
- [x] 4.2 Editar `app/resources/views/files/index.blade.php` mapa `noEscaneado`: añadir entrada `path_unreadable: 'el directorio no es legible por el usuario del servidor'`.
- [x] 4.3 Verificar que `path_missing` se mantiene para el caso "no existe físicamente" (regression check: navegar a un parent_id inexistente debe seguir mostrando el mensaje viejo).
- [x] 4.4 Smoke test: navegar a `06 Emisoras 05 > Casanare > Yopal_Manatial` antes del repair debe mostrar el nuevo mensaje "el directorio no es legible...".
- [x] 4.5 Smoke test: post-repair, el toast debe desaparecer y el listado cargar desde disco.

## 5. Schedule del audit

- [x] 5.1 Editar `app/routes/console.php`: añadir `Schedule::command('storage:audit-ownership')->hourly()->withoutOverlapping(60)`.
- [x] 5.2 Verificar con `php artisan schedule:list` que el comando aparece con cadencia hourly.

## 7. Dashboard admin partial (frontend + backend)

- [x] 7.1 Implementar `App\Services\Dashboard\DashboardDataProvider::buildAdminStorageHealthData(): array` que lee `SystemSetting('storage_health:*:untraversable_count')` y `SystemSetting('storage_health:*:owner_mismatch_count')` y filtra por >0; cross-reference con `storage_providers` para nombre legible.
- [x] 7.2 Crear `app/resources/views/dashboard/partials/_storage_health.blade.php` con header documentando shape, gating por `$data['enabled']` y `$data['items']` no vacío, render condicional con `data-dashboard-partial="storage-health"`, comandos CLI a copiar.
- [x] 7.3 Editar `app/resources/views/dashboard/admin.blade.php` para incluir `@include('dashboard.partials._storage_health', ['context' => 'admin', 'data' => $dashboardData['storage_health']])` después del bloque Módulos Inteligentes.
- [x] 7.4 Verificar que `user.blade.php` NO referencia el partial nuevo (cliente no debe verlo).
- [x] 7.5 Verificar que el `DashboardController::buildClient()` NO añade `storage_health` al `$dashboardData`.
- [x] 7.6 Smoke test con admin: cargar `/dashboard` post-audit debe mostrar storage 55 con "8 untraversable" + comando `storage:repair-ownership`.
- [x] 7.7 Smoke test con cliente: cargar `/dashboard` NO debe contener el contenedor `data-dashboard-partial="storage-health"` en el HTML.

## 8. Test harness de regresión

- [x] 8.1 Crear `app/tests/harness_storage_readability_audit.php` con aserciones: untraversable detectado en fixture con path bloqueado; owner-mismatch detectado en fixture con owner != www; denylist respetado (System Volume Information nunca aparece); UID gate en repair (corre como www → abort); --yes skip confirmation; snapshot pre-repair creado con conteo correcto; tabla no droppeada al terminar.
- [x] 8.2 Extender `app/tests/harness_dashboard_partials.php` con aserciones del partial nuevo: gating cuando flag desactivado, gating cuando 0 hallazgos, render cuando hay hallazgos, admin-only (cliente no ve contenedor), presencia de `data-dashboard-partial="storage-health"`.
- [x] 8.3 Ejecutar `php tests/harness_storage_readability_audit.php` desde `app/` y confirmar exit 0.
- [x] 8.4 Ejecutar `php tests/harness_dashboard_partials.php` desde `app/` y confirmar exit 0.

## 9. Validación end-to-end

- [x] 9.1 Dry-run del audit: `php artisan storage:audit-ownership --storage=55` debe reportar `untraversable: 8`.
- [x] 9.2 Confirmar visualmente que `System Volume Information` aparece en `metadata_skip`, no en `untraversable`.
- [x] 9.3 Inspeccionar manualmente `/www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_H/RD6/Casanare` (no debe ser accesible para www antes del repair).
- [x] 9.4 Dry-run del repair: `sudo php artisan storage:repair-ownership --storage=55` debe listar 8 paths.
- [x] 9.5 Apply del repair: `sudo php artisan storage:repair-ownership --storage=55 --apply --yes` debe cambiar owner/group/mode de los 8 paths.
- [x] 9.5.1 Si storage está sobre NFS4 con `root_squash` (caso de Disco_H = `192.168.0.112:/mnt/grabador/radios2`), el chown va a fallar con "Permission denied" o "Operation not permitted". Documentar limitación en AGENTS.md y aplicar chown desde el NFS server (`192.168.0.112`) vía SSH, o cambiar `no_root_squash` en el export.
- [x] 9.6 Re-audit post-repair: `php artisan storage:audit-ownership --storage=55` debe reportar `untraversable: 0`.
- [x] 9.7 Confirmar que el usuario puede navegar a `Casanare > Yopal_Manatial` y ver el listado real desde disco (no desde BD stale).
- [x] 9.8 Cargar `/dashboard` como admin y confirmar que la tarjeta aparece con conteo en 0 o desaparece (cero hallazgos → no render).
- [x] 9.9 Confirmar que el schedule aparece en `php artisan schedule:list`.

## 10. Documentación y cleanup

- [x] 10.1 Actualizar AGENTS.md con sección de runbook: cómo correr audit, cómo repair, cómo hacer rollback desde tabla snapshot.
- [x] 10.2 Confirmar que NO se commitea el `openspec/change.lock` (debe quedar solo en working tree).
- [x] 10.3 Confirmar que NO se commitea ningún secret del usuario operador (sudo password, etc.).
- [x] 10.4 Marcar la change como `openspec archive` solo después de que la validación end-to-end esté completa en producción.
- [x] 10.5 Commit con conventional commit: `feat(storage): detect and repair untraversable children of storage base_path`.