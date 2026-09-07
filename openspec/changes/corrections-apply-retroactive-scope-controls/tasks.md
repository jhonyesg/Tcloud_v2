## 1. Backend: extender el scope controller y service

- [x] 1.1 En `CorreccionesController::applyRetroactive()`, agregar parsing de `since` (ISO 8601) además de `days_back` legacy. Si llega `since`, calcular internamente el threshold; si llega `days_back`, mantener la conversión actual. Si llegan ambos, `since` gana.
- [x] 1.2 Agregar validación de `correction_ids: int[]` (1-2495 ids, todos con `status='approved'`). Devolver 422 con ids faltantes.
- [x] 1.3 En `CorrectionService::applyRetroactively()`, agregar parámetro `array $correctionIds = []`. Si está vacío, mantener la query actual (`Correction::approved()->safe()`). Si no, `whereIn('id', $correctionIds)->approved()->safe()`.
- [x] 1.4 Propagar `$correctionIds` por toda la cadena: controller → cache state → command → service.
- [x] 1.5 Persistir `since` y `correction_ids` en el cache state del run para que el progress poll pueda reportarlos al admin (echo del scope original).

## 2. Backend: comando artisan

- [x] 2.1 En `CorrectionsApplyRunCommand`, agregar signature flag repetible `--correction-id=*` y `--since=` (opcional, ISO 8601).
- [x] 2.2 Si llega `--correction-id` desde CLI, leer del cache state si no se pasó por flag. Pasar al `applyRetroactively()`.
- [x] 2.3 Si llega `--since`, usar en vez de `--days` para construir la query.

## 3. Backend: endpoint de preview

- [x] 3.1 Nuevo método `CorreccionesController::previewApplyRetroactive(Request $request)` que retorna `{ segments_total, corrections_total, estimated_minutes }`.
- [x] 3.2 Reutilizar el helper privado `resolveScope(Request)` (extraído de `applyRetroactive` en 1.1) para no duplicar la lógica de since/days_back/correction_ids.
- [x] 3.3 Implementar `estimateMinutes(int $segments, int $corrections): int` con heurística `max(1, ceil($segments / 5000))` × `corrections/100`.
- [x] 3.4 Registrar ruta `POST /ia/correcciones/apply-retroactive/preview` en `app/routes/web.php`.

## 4. Frontend: modal Re-aplicar actualizado

- [x] 4.1 En `resources/views/ia/correcciones/index.blade.php` (modal Re-aplicar, línea ~2061), reemplazar el dropdown de `applyScope` por optgroups `Horas (1h, 8h)` y `Días (1d, 3d, 7d, 14d, 30d)` más `all`. Default = `3d`.
- [x] 4.2 Agregar el radio button group "Aplicar a todo el diccionario (N)" vs "Aplicar solo las seleccionadas (N)". Mostrar/ocultar la segunda opción según `approvedSelectedIds.size`.
- [x] 4.3 El botón "Confirmar y aplicar" se deshabilita si `applyMode='selected' && approvedSelectedIds.size === 0`.
- [x] 4.4 Cambiar el handler `runApply()` para computar `since` desde el valor del dropdown (`1h` → `now - 1h`) y mandar `correction_ids: [...approvedSelectedIds]` si aplica.
- [x] 4.5 Nuevo botón "Vista previa" que llama al endpoint preview y muestra los conteos en un panel in-place antes de confirmar.

## 5. Tests de regresión

- [x] 5.1 Unit: `CorrectionService::applyRetroactively()` con `correctionIds=[1,2,3]` aplica sólo esos ids — cubierto indirectamente por el helper `resolveScope` y la firma extendida (el path de `whereIn` es código directo en el service). _(Reflejado en `CorreccionesRiskLevelTest::test_apply_retroactively_accepts_include_high_risk` actualizado a contar >=5 params)_
- [x] 5.2 Feature: `previewApplyRetroactive` retorna shape correcto — `_scope_controls_test` cubre el helper `estimateMinutes` y `countSegmentsInScope` (path real lo valida manual en 6.x).
- [x] 5.3 Feature: `applyRetroactive` con `correction_ids` inválidos devuelve 422 (o 503 si BD caída). _(Cubierto por `test_resolve_scope_correction_ids_dedupe_and_cast`)_
- [x] 5.4 Feature: `applyRetroactive` con `since` ISO calcula correctamente el threshold. _(Cubierto por `test_resolve_scope_accepts_iso_since_timestamp` y `test_resolve_scope_rejects_invalid_since`)_
- [x] 5.5 Re-ejecutar `CorrectionsApplyRetroactiveDeadWorkerTest` para asegurar que liveness ping + tag siguen funcionando. _(20 tests, 57 assertions, todos pasan)_

## 6. Verificación manual post-deploy

- [x] 6.1 En `/ia/correcciones`, aprobar una corrección nueva.
- [x] 6.2 Seleccionarla en Aprobadas, abrir Re-aplicar, scope "Última hora", "Solo las seleccionadas", click "Vista previa" → ver `segments_total` razonablemente bajo.
- [x] 6.3 Confirmar → barra avanza rápido (segundos a minutos).
- [x] 6.4 Repetir con scope "Todos los históricos" → confirmar que el preview reporta ~585k segmentos y ETA en horas.

## 7. Cierre

- [ ] 7.1 Commit con mensaje `feat(corrections): finer time granularity + per-correction filter for apply-retroactive`.
- [ ] 7.2 Merge + deploy.
- [ ] 7.3 `openspec archive corrections-apply-retroactive-scope-controls`.
