## 1. Auditoría previa

- [ ] 1.1 Inventariar todos los callers de `AvisosScanService::coverage()` (versión legacy no paginada). `grep -rn "coverage(" app/app --include="*.php" | grep -v coveragePaginated`. Esperado: solo el archivo del servicio actual, ningún caller. Si hay callers externos, marcarlos para migración.
- [ ] 1.2 Verificar que `system_settings` admite operaciones atómicas de UPDATE. Confirmado en design del change anterior; revalidar.
- [ ] 1.3 Medir cardinalidad actual de `watermark_audit_log`. Esperado: < 100 filas (estamos recién empezando). Si > 10k, evaluar si la primera corrida de archive es viable.

## 2. Cache epoch + invalidación

- [ ] 2.1 Crear `app/app/Services/Ia/CacheEpoch.php` con `get(): int` y `bump(): int`. Usa `SystemSetting::get('coverage_cache_epoch', 0)` y `SystemSetting::set('coverage_cache_epoch', $new)`.
- [ ] 2.2 En `WatermarkReconciler`, añadir `bumpEpoch()` privado llamado al final de `rewindPair`, `ensureForUser`, `ensureForKeyword`, `ensureForStorage` (solo si la mutación efectivamente insertó/actualizó filas).
- [ ] 2.3 Modificar `AvisosScanService::coveragePaginated` para usar `CacheEpoch::get()` en la cache key.
- [ ] 2.4 Inicializar `system_settings.coverage_cache_epoch = 0` en una migración (`2026_09_22_120100_initialize_coverage_cache_epoch.php`) si no existe.

## 3. Migración archive table

- [ ] 3.1 Crear `app/database/migrations/2026_09_22_120000_create_watermark_audit_log_archive_table.php` con la misma estructura que `watermark_audit_log` + columna `archived_at` + 3 índices.
- [ ] 3.2 Aplicar migración. Verificar schema con `\d watermark_audit_log_archive`.

## 4. AuditLogArchiver + comando

- [ ] 4.1 Crear `app/app/Services/Ia/AuditLogArchiver.php` con métodos:
  - `countOlderThan(int $days): int`
  - `archive(int $days): array` (transaccional por chunks de 1000)
- [ ] 4.2 Crear `app/app/Console/Commands/ArchiveAuditLogCommand.php` (`avisos:archive-audit-log --days=N --dry-run`).
- [ ] 4.3 Probar en staging con `--dry-run` para confirmar conteo.
- [ ] 4.4 (Opcional) Cron hint en `routes/console.php`: `Schedule::command('avisos:archive-audit-log --days=90')->monthlyOn(1, '03:00');` — comentado por defecto; el operador lo activa.

## 5. Endpoint de consulta audit log

- [ ] 5.1 Añadir método `auditLog(array $filters, int $perPage, int $page): array` en `AvisosScanService` (paginación + filtros + JOIN con keywords y storage_providers).
- [ ] 5.2 Añadir endpoint `GET /avisos-inteligentes/scan/audit` en el controller. Acepta: `page`, `per_page`, `actor`, `action`, `keyword`, `storage`, `since`, `until`. Devuelve JSON con `items`, `total`, `current_page`, `last_page`.
- [ ] 5.3 Añadir ruta en `routes/web.php` dentro del grupo admin (auth+admin middleware).
- [ ] 5.4 Verificar con EXPLAIN que las queries típicas usan los índices `wal_*_idx`.

## 6. Endpoint export CSV

- [ ] 6.1 Añadir método `exportAuditCsv(array $filters): StreamedResponse` en el controller.
- [ ] 6.2 Añadir ruta `GET /avisos-inteligentes/scan/audit/export` con los mismos filtros.
- [ ] 6.3 Verificar que el CSV respeta el charset UTF-8 (BOM opcional).

## 7. UI sub-pestaña Auditoría + exportación CSV

- [ ] 7.1 Añadir sub-pestaña "Auditoría" dentro de la pestaña Cobertura en `index.blade.php`.
- [ ] 7.2 Tabla con columnas: timestamp, actor, action, keyword, storage, before, after.
- [ ] 7.3 Filtros arriba: actor (input), action (select), keyword (input), storage (input), since/until (date inputs).
- [ ] 7.4 Botón "Exportar CSV" que descarga el archivo filtrado.
- [ ] 7.5 Paginación 25/50/100 con misma UI que la pestaña Cobertura.

## 8. Endpoint + UI full scan background

- [ ] 8.1 Añadir endpoint `POST /avisos-inteligentes/scan/full-bg` (admin): encola worker con cache `full_scan_bg:{runId}`. Anti-duplicado via pointer `full_scan_bg:active`.
- [ ] 8.2 Añadir endpoint `GET /scan/full-bg/{runId}/status`.
- [ ] 8.3 Añadir endpoint `POST /scan/full-bg/{runId}/stop`.
- [ ] 8.4 Modificar UI Cobertura: botón "Escaneo completo" usa `/full-bg` y hace polling cada 2s. Modal muestra: iteración, escaneadas, hits, tiempo transcurrido.
- [ ] 8.5 Cleanup: el endpoint `POST /scan/full` (sincrónico) se conserva como fallback para admin que prefiera esperar bloqueando.

## 9. Hygiene: UserStorage::created, fix_orphans, deprecate

- [ ] 9.1 En `app/app/Models/UserStorage.php`, añadir hook `static::created` que llama `WatermarkReconciler::ensureForUser` si `transcription_access=true`.
- [ ] 9.2 En `AvisosScanService` o nuevo endpoint, añadir método `fixOrphans(?int $userId): array` que borra los pares huérfanos y registra en audit_log.
- [ ] 9.3 Modificar `AvisosInteligentesController::runReconcile` para aceptar `fix_orphans=true, confirmed=true` y ejecutar `fixOrphans`.
- [ ] 9.4 Marcar `AvisosScanService::coverage()` con `@deprecated since 2026-09-22 use coveragePaginated()` + `trigger_error(E_USER_DEPRECATED)` cuando `APP_DEBUG=true`.
- [ ] 9.5 Añadir validación al inicio de `rewindWatermark`: verificar que keyword y storage existen (404 si no).

## 10. Tests (harnesses)

- [ ] 10.1 `tests/harness_audit_log_archiver.php`: inserta 50 filas con `created_at` viejo, ejecuta archive, verifica que `watermark_audit_log_archive` las tiene y `watermark_audit_log` no.
- [ ] 10.2 `tests/harness_cache_epoch_invalidation.php`: hace rewind, verifica que epoch incrementa; siguiente GET de coverage retorna datos frescos (sin esperar TTL).
- [ ] 10.3 `tests/harness_full_scan_polling.php`: lanza full scan via endpoint background, hace polling, verifica que `runId` retorna estado consistente.
- [ ] 10.4 `tests/harness_fix_orphans.php`: crea drift positivo, ejecuta fix_orphans, verifica borrado + audit_log.
- [ ] 10.5 Re-ejecutar los 3 harnesses de los changes anteriores para validar cero regresión.

## 11. Documentación

- [ ] 11.1 Actualizar `AGENTS.md`:
  - Convención: "toda mutación incrementa `coverage_cache_epoch` antes de retornar".
  - Convención: "consultas al audit log pasan por `GET /scan/audit` (no SQL directo)".
  - Runbook operativo: `avisos:archive-audit-log`, polling del full scan.
- [ ] 11.2 Comentarios en `WatermarkReconciler` y `AvisosScanService` sobre `coverage()` deprecado.

## 12. Despliegue

- [ ] 12.1 Aplicar migraciones en staging (audit_log_archive + cache_epoch init). Verificar schema.
- [ ] 12.2 Probar manualmente: rewind + UI, full scan background + polling, archive dry-run.
- [ ] 12.3 Aplicar en producción. Verificar que los hooks siguen disparando.
- [ ] 12.4 Activar cron hint de `avisos:archive-audit-log` si el operador lo aprueba.

## 13. Backlog (futuro, fuera de scope)

- [ ] 13.1 Particionar `watermark_audit_log` por `created_at` (mes) cuando supere 100k filas.
- [ ] 13.2 Exportador a Excel/Sheets (XLSX) además de CSV.
- [ ] 13.3 UI de auditoría con vista timeline (calendario visual).
- [ ] 13.4 Activación real de la partición de `segment_keyword_hits` cuando > 10M filas.
