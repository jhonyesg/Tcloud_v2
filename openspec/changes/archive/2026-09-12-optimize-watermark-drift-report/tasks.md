## 1. Refactor de driftReport

- [x] 1.1 Reescribir el cálculo de `missing` en `WatermarkReconciler::driftReport()` con set-difference por hash (`array_diff_key`) en vez de `contains()` anidados.
- [x] 1.2 Reescribir el cálculo de `orphan` con el mismo enfoque hash.
- [x] 1.3 Preservar exactamente el shape de retorno: `summary` (`applicable_pairs`, `existing_pairs`, `missing`, `orphan`, `scope_user_id`) y arrays `missing[]`/`orphan[]` con `keyword_id`/`storage_provider_id`.
- [x] 1.4 Mantener soporte del parámetro `?int $userId` con el mismo filtrado por scope.

## 2. Verificación

- [x] 2.1 Correr `tests/harness_watermark_reconciler_audit.php` (valida drift puntual y scope por usuario).
- [x] 2.2 Correr `tests/harness_coverage_dashboard.php` y `tests/harness_dashboard_tiered_cache.php`.
- [x] 2.3 Medir latencia de `driftReport()` antes/después y confirmar paridad de conteos (missing/orphan).
- [x] 2.4 Verificar en navegador que recargar `/dashboard` tras navegar por módulos de IA ya no tarda (Playwright existente).
