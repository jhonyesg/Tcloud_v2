## Why

El change `avisos-keyword-storage-watermark` dejó la cobertura inicial de escaneo sincronizada (seed inicial + hook `Keyword::created` + hook `UserAlertsInteligente::saved`), pero hay tres huecos de sostenibilidad operativa que aparecen con el uso real:

- **(12.1.a) Hueco de cobertura ante asignaciones post-deploy**: cuando un usuario recibe una keyword ya existente (admin la asigna, importación masiva, importación de cliente nuevo con keywords pre-existentes), no se crea watermark para sus storages con acceso. Resultado: el nuevo par `(keyword, storage)` nunca es escaneado hasta intervención manual del admin.
- **(12.1.b) Hueco ante cambio de acceso a storage**: si un usuario pierde `transcription_access` (true→false) y luego lo recupera (false→true), puede tener el watermark con datos obsoletos de la época en que tenía acceso. Hoy el watermark persiste; sin rewind explícito, esa sesión de "estuvo sin acceso" se ignora (lo cual es aceptable, pero hay que documentarlo).
- **(12.3) Crecimiento de `segment_keyword_hits`**: hoy 12k filas / 4.4 MB. La retención de histórico (>90 días) requerirá `DELETE FROM ... WHERE matched_at < ?` que a escala (millones de filas) genera vacuum intensivo y lock contention. Un `DROP PARTITION` mensual es O(1) en lugar de O(N).

Adicionalmente, durante la auditoría del estado actual detecté:

- **Seguridad**: los endpoints `rewindWatermark` y `runFullScan` no registran quién (admin_id) ni cuándo ni sobre qué par. En producción, un cambio de cobertura debería quedar en audit log por compliance.
- **UX**: la pestaña Cobertura devuelve TODOS los pares en una sola request (518 hoy, escalará). Necesita paginación server-side igual que el resto del módulo (`mis-avisos-table-navigation`).
- **Sostenibilidad**: la lógica de reconciliación está duplicada entre `Keyword::created` y `UserAlertsInteligente::saved` (mismo SQL INSERT IGNORE). Centralizar en `WatermarkReconciler` reduce duplicación y facilita testing.

## What Changes

- Nuevo servicio `App\Services\Ia\WatermarkReconciler` con `ensureForUser(int $userId)`, `ensureForKeyword(int $keywordId)`, `ensureForStorage(int $storageId)` y `driftReport()`. Centraliza la lógica de seed/upsert.
- Hook `UserKeyword::created` y `saved`: invoca `WatermarkReconciler::ensureForKeyword($keywordId)` cuando un usuario recibe una keyword.
- Hook `user_storages` (modelo nuevo `UserStorage`) sobre `transcription_access`: si transición false→true, `rewindToNull($userId, $storageId)` para forzar re-escaneo. Si true→false, no hace nada (el watermark persiste; el scan no emitirá candidatos porque la condición `transcription_access=true` no se cumple).
- Nuevo comando CLI `avisos:reconcile-watermarks [--dry-run] [--user=ID]` que ejecuta el reconciler sobre todo el sistema y reporta drift (pares faltantes, pares huérfanos).
- Nueva tabla `watermark_audit_log` con `actor_user_id`, `action` (rewind|rewind_pair|full_scan|hook_auto), `keyword_id`, `storage_id`, `before_scanned_until`, `after_scanned_until`, `created_at`. Permite responder "¿quién movió este par a NULL?" con una sola query.
- Middleware ligero `AuditAdminAction` aplicado a los 2 endpoints sensibles (`rewind`, `runFullScan`): inyecta actor_user_id y registra en `watermark_audit_log`.
- Particionamiento `RANGE` por mes en `segment_keyword_hits`: tabla padre `segment_keyword_hits` se convierte en tabla particionada; partición por mes. Migración nueva crea partición del mes actual + mes anterior; comando CLI `avisos:ensure-month-partition` extiende automáticamente (cron mensual). FKs y UNIQUE triple se ajustan para incluir `matched_at`.
- UI: la pestaña Cobertura pagina los resultados a 25/50/100 por página, con filtros por storage y por keyword. El botón "Activar histórico" muestra en el confirm cuántos pares se verán afectados (preview). El botón "Escaneo completo" hace polling y muestra el progreso en tiempo real.
- Hooks existentes (`Keyword::created`, `UserAlertsInteligente::saved`) refactorizados para delegar al nuevo `WatermarkReconciler` (sin cambio de comportamiento).

## Capabilities

### New Capabilities
- `avisos-scan-coverage-reconciler`: sincronización automática de cobertura ante cambios de scope + reconciler periódico + reporte de drift.
- `avisos-scan-coverage-audit`: audit log de acciones sensibles sobre watermarks (rewind, full scan, hooks automáticos).
- `segment-keyword-hits-partitioning`: estrategia de particionamiento mensual con soporte para retención via DROP PARTITION.

### Modified Capabilities
- `avisos-keyword-storage-watermark`: delega a `WatermarkReconciler`; expone hooks reactivos a cambios de scope (UserKeyword, UserStorage).
- `admin-destructive-actions-loading-state`: el botón "Escaneo completo" usa polling estructurado con feedback en tiempo real (extiende el patrón existente).

## Impact

- **Migraciones nuevas**:
  - `2026_09_15_120000_create_watermark_audit_log_table.php` (nueva tabla, índice por keyword+storage+time).
  - `2026_09_15_120100_partition_segment_keyword_hits_by_month.php` (DDL masivo: requiere downtime corto de 5-10 min por la copia inicial).
- **Archivos nuevos**:
  - `app/app/Services/Ia/WatermarkReconciler.php`
  - `app/app/Console/Commands/ReconcileWatermarksCommand.php`
  - `app/app/Console/Commands/EnsureMonthPartitionCommand.php`
  - `app/app/Http/Middleware/AuditAdminAction.php`
  - `app/app/Models/UserKeyword.php` (si no existe) o hook directo
  - `app/app/Models/UserStorage.php` (si no existe) o hook directo
- **Modificados**:
  - `app/app/Models/Keyword.php` (delega a reconciler en lugar de SQL inline)
  - `app/app/Models/UserAlertsInteligente.php` (delega a reconciler)
  - `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (rewind y runFullScan registran auditoría)
  - `app/routes/web.php` (middleware en los 2 endpoints)
  - `app/resources/views/ia/avisos-inteligentes/index.blade.php` (paginación, polling, preview)
- **Tests**:
  - `tests/harness_watermark_reconciler_drift.php`: simula huecos y verifica reconciliación.
  - `tests/harness_audit_log_admin_actions.php`: verifica que rewind/runFullScan dejan traza.
  - `tests/harness_partition_segment_keyword_hits.php`: valida inserción por mes y DROP PARTITION.
- **Downtime requerido**: la partición de `segment_keyword_hits` requiere ventana de mantenimiento (~5-10 min mientras PG copia las 12k filas + recrea índices). Coordinar con el admin para horario de bajo tráfico.
- **Riesgo de retrocompatibilidad**: la conversión de `segment_keyword_hits` a tabla particionada requiere DROP/RECREATE. La migración hace `ALTER TABLE ... ATTACH PARTITION` que es online para datos nuevos pero requiere lock corto para los iniciales. Plan: backup pre-migración + rollback documentado (cambiar a tabla no particionada).
