## Why

Tras el change `dashboard-tiered-cache`, el dashboard seguía volviéndose lento al recargar después de navegar por módulos de IA. La causa: `WatermarkReconciler::driftReport()` tarda **~1.2 s** (medido) por comparar los pares aplicables (1245) contra los watermarks existentes (910) con `Collection::contains()` anidados — O(n×m) ≈ 2.3 M comparaciones en PHP. Ese cálculo vive en el tier tibio del dashboard y se recalcula en línea cada vez que un escaneo bumpea el `CacheEpoch` (que es frecuente al navegar por Avisos/Transcriptor) o cuando la entrada expira.

## What Changes

- Reescribir el cálculo de `missing` (drift negativo) y `orphan` (drift positivo) en `WatermarkReconciler::driftReport()` usando **set-difference por hash** O(n+m) sobre arrays claveados por `keyword_id:storage_provider_id`, en lugar de `contains()` anidados.
- Mantener **idéntico** el shape de retorno (`summary`, `missing[]`, `orphan[]` con `keyword_id`/`storage_provider_id`) y los mismos conteos. No es un cambio de comportamiento, es de complejidad algorítmica.
- Añadir un harness de regresión que valide paridad de resultados y la mejora de latencia.

## Non-goals

- No se cambia la semántica de drift ni qué pares se consideran aplicables/huérfanos.
- No se toca la invalidación por epoch del dashboard ni los TTLs (ya correctos).
- No se migra el cálculo a SQL puro en v1 (el hash en PHP ya baja a milisegundos).
- No se modifica `ensureForKeyword`, `rewindPair` ni la auditoría.

## Impact

- `app/app/Services/Ia/WatermarkReconciler.php` (método `driftReport`).
- Consumidores sin cambios: `ReconcileWatermarksCommand`, `DashboardService::coverageSummary()`, `DashboardService::build()`.
- **Sin migración.** Sin cambios de esquema ni de contrato.
- Regresión cubierta por `tests/harness_watermark_reconciler_audit.php` y un harness nuevo de paridad/latencia.
