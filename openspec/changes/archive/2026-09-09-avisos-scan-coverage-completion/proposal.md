## Why

El módulo de cobertura de watermarks está completo en su parte admin (4 cambios archivados) pero le falta la pieza client-facing: hoy un cliente no puede ver SU cobertura de keywords ni solicitar rewind, debe pedirle al admin. Además faltan 4 utilidades que completan el módulo: exportador XLSX (no solo CSV) para audit log, readiness check que anticipe cuándo toca particionar las tablas grandes, política de retención ajustable sin recompilar, y métricas históricas de cobertura para detectar regresiones. Cierra los pendientes 12.3, 13.1 (readiness), 13.2 (XLSX) y 13.4.

## What Changes

- **(1) Client-facing coverage**: nuevo endpoint `GET /mis-avisos/coverage` (auth, no admin) que retorna las keywords del usuario autenticado con su cobertura per-storage. UI: nueva pestaña "Cobertura" dentro de la vista Mis Avisos del cliente (`/mis-avisos`). Cada cliente ve SOLO sus pares (k,s), nunca los de otros clientes. Acción permitida al cliente: solicitar rewind (que pasa por admin para aprobación o directo con throttling).
- **(2) XLSX exporter**: nuevo endpoint `GET /avisos-inteligentes/scan/audit/export-xlsx` con los mismos filtros que el CSV. Usa formato XLSX nativo (openpyxl-style simple o libreria nativa PHP). Genera headers correctos para Excel (UTF-8 BOM, fechas serializadas correctamente).
- **(3) Partitioning readiness check**: nuevo comando `avisos:check-partitioning-readiness` que reporta el tamaño actual de `watermark_audit_log` y `segment_keyword_hits` vs los thresholds (100k y 10M filas respectivamente). Emite WARNING cuando se acerque (>70%) y CRITICAL cuando se supere (>100%). Output en formato tabla con recomendaciones.
- **(4) Política de retención ajustable**: nueva SystemSetting `audit_log_retention_days` (default 90). El comando `avisos:archive-audit-log` lee este setting si `--days` no se pasa. Documentar en AGENTS.md.
- **(5) Métricas históricas**: nuevo endpoint `GET /avisos-inteligentes/scan/coverage/stats` que retorna evolución diaria de pares totales / pendientes / con hits / huérfanos últimos 30 días (basado en `created_at` de `keyword_scan_watermarks`). Útil para detectar regresiones de cobertura.
- Sin breaking changes.

## Capabilities

### New Capabilities
- `avisos-scan-coverage-client-facing`: endpoint y UI para que cada cliente vea su cobertura y solicite rewind.
- `avisos-scan-coverage-xlsx-export`: exportador XLSX del audit log.
- `avisos-scan-coverage-partition-readiness`: comando proactivo de alerta de particionamiento.
- `avisos-scan-coverage-historical-stats`: endpoint de métricas de evolución.

### Modified Capabilities
- `avisos-scan-coverage-audit`: política de retención configurable vía SystemSetting `audit_log_retention_days`.

## Impact

- **Archivos nuevos**:
  - `app/app/Console/Commands/CheckPartitioningReadinessCommand.php`
  - `app/app/Services/Ia/RetentionPolicy.php`
  - `app/app/Services/Ia/CoverageStats.php`
  - `app/app/Http/Controllers/MisAvisosCoverageController.php` (ruta cliente)
  - `app/resources/views/mis-avisos/coverage.blade.php` (sub-vista cliente)
  - `app/tests/harness_coverage_completion.php`
- **Archivos modificados**:
  - `app/routes/web.php` (rutas nuevas: /mis-avisos/coverage, /avisos-inteligentes/scan/audit/export-xlsx, /avisos-inteligentes/scan/coverage/stats)
  - `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (XLSX + stats + retention setting)
  - `app/app/Console/Commands/ArchiveAuditLogCommand.php` (lee retention policy)
  - `app/resources/views/layouts/app.blade.php` (link a Mis Avisos si no existe)
  - `app/database/migrations/2026_09_30_120000_initialize_audit_log_retention_days.php` (SystemSetting default 90)
  - `AGENTS.md` (runbook de retention + readiness check)
- **Tests nuevos**:
  - `tests/harness_coverage_completion.php`: valida (1) GET /mis-avisos/coverage solo retorna los pares del usuario autenticado; (2) export-xlsx devuelve XLSX válido; (3) check-partitioning-readiness reporta correctamente; (4) retention setting leído del SystemSetting.
- **Sin downtime, sin breaking changes**.
