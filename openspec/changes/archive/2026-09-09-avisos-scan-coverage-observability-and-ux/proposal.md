## Why

El change anterior dejó el audit log funcionando pero sin UI: para responder "¿quién movió este par?" hay que escribir SQL. Además, dos inconsistencias de UX detectadas durante implementación (cache stale de 60s tras rewind; full scan sin progreso en vivo) hacen que el admin dude de lo que ve. Y tres huecos pequeños (UserStorage::created hook, huérfanos sin auto-fix, coverage() legacy) siguen latentes. Un solo change autocontenido cierra los tres ángulos sin downtime.

## What Changes

- **(A) Compliance**: nuevo endpoint `GET /avisos-inteligentes/scan/audit` paginado y filtrable (actor, action, keyword, since, until); nueva sub-pestaña "Auditoría" en la UI Cobertura con tabla + búsqueda; nuevo comando `avisos:archive-audit-log --days=N` que mueve filas viejas de `watermark_audit_log` a `watermark_audit_log_archive` (misma estructura, particionable por fecha en el futuro); cron hint mensual en `routes/console.php`.
- **(B) UX consistente**: cache key de `coverage()` añade un "epoch" que se incrementa en cada mutación (rewind, reconcile, hook); nuevo endpoint background `POST /avisos-inteligentes/scan/full-bg` (runId) + `GET .../full-bg/{runId}/status` con polling cada 2s desde Alpine; el botón "Escaneo completo" deja de bloquear el modal y muestra progreso en vivo.
- **(C) Higiene**: hook `UserStorage::created` que dispara `ensureForUser` cuando se inserta directamente con `transcription_access=true` (caso que el `updated` hook no cubre); endpoint `POST /scan/reconcile?fix_orphans=true` para borrar huérfanos bajo confirmación (admin debe confirmar N pares a borrar); `AvisosScanService::coverage()` se marca `@deprecated` con docblock apuntando a `coveragePaginated()` (no se borra por compatibilidad); `rewindWatermark` valida antes de mutar que `(keyword_id, storage_provider_id)` es referenciable (no permite rewinds sobre pares donde el storage ya fue borrado).
- **BREAKING**: ninguno. Todos los cambios son aditivos o deprecaciones suaves.

## Capabilities

### New Capabilities
- `avisos-scan-coverage-audit-ui`: panel admin con búsqueda y exportación del audit log + política de archivado.
- `avisos-scan-coverage-observability`: cache epoch, polling del full scan y validación de rewind.

### Modified Capabilities
- `avisos-scan-coverage-reconciler`: hook UserStorage::created, endpoint fix_orphans.
- `avisos-scan-coverage-audit`: comando `avisos:archive-audit-log` y tabla `watermark_audit_log_archive`.

## Impact

- **Migraciones nuevas**:
  - `2026_09_22_120000_create_watermark_audit_log_archive_table.php` (estructura idéntica a `watermark_audit_log` + índice `(created_at)`).
- **Archivos nuevos**:
  - `app/app/Console/Commands/ArchiveAuditLogCommand.php`
  - `app/app/Services/Ia/AuditLogArchiver.php`
  - `app/app/Services/Ia/CacheEpoch.php` (utilidad para invalidar cache via contador)
- **Modificados**:
  - `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (audit endpoint, full-bg endpoints, fix_orphans, rewind validation)
  - `app/app/Services/Ia/AvisosScanService.php` (coverage() deprecado; coveragePaginated usa CacheEpoch)
  - `app/app/Services/Ia/WatermarkReconciler.php` (incrementa epoch en cada mutación)
  - `app/app/Models/UserStorage.php` (hook `created`)
  - `app/resources/views/ia/avisos-inteligentes/index.blade.php` (sub-pestaña Auditoría + polling de full scan)
  - `app/routes/web.php` (3 endpoints nuevos + middleware)
  - `app/routes/console.php` (cron hint mensual para archive-audit-log)
- **Tests nuevos**:
  - `tests/harness_audit_log_archiver.php`: archiva filas viejas, verifica que `watermark_audit_log_archive` las tiene y `watermark_audit_log` no.
  - `tests/harness_cache_epoch_invalidation.php`: hace rewind, verifica que el siguiente GET de coverage devuelve datos frescos.
  - `tests/harness_full_scan_polling.php`: lanza full scan en background, hace polling, verifica que `runId` retorna estado consistente.
- **Sin downtime**: todas las operaciones son online; la migración crea tabla nueva; los hooks se añaden en modelos sin tocar el contrato público.
- **Riesgo de compatibilidad**: `coverage()` se depreca pero sigue existiendo. Si alguien lo usa hoy, recibe un warning de IDE (docblock `@deprecated`) y un mensaje en runtime (`Log::warning`). No se rompe comportamiento.
