## Why

Durante la validación E2E del change `fix-avisos-scan-by-months-no-saturation`, el worker mensual procesó exitosamente el mes `2026-07` (50 candidatos, `mode: 'monthly'`, `months_done: 1/3`, `current_month: '2026-08'`) y se mantuvo en **una sola conexión activa** de PostgreSQL durante todo el ciclo. Sin embargo, al intentar escribir los watermarks del matching, surgió un error pre-existente:

```
SQLSTATE[21000]: Cardinality violation: ON CONFLICT DO UPDATE command
cannot affect row a second time
INSERT INTO keyword_scan_watermarks
  (keyword_id, storage_provider_id, scanned_until, ...)
VALUES (94, 12, '2026-07-11 05:50:18', ...),
       (95, 12, '2026-07-11 05:50:18', ...),
       (117, 12, '2026-07-11 05:50:18', ...),
       (94, 12, '2026-07-11 06:05:11', ...),   ← duplicado: keyword_id=94, storage=12
       (95, 12, '2026-07-11 06:05:11', ...),
       (117, 12, '2026-07-11 06:05:11', ...),
       ...
```

El mismo `(keyword_id, storage_provider_id)` aparece múltiples veces dentro del mismo INSERT porque la tanda procesa varias transcripciones que tocan el mismo par keyword×storage. PostgreSQL rechaza el `ON CONFLICT DO UPDATE` cuando hay duplicados dentro del mismo batch (`ON CONFLICT` solo deduplica contra filas ya commiteadas, no dentro del mismo batch).

El bug NO es nuevo: existe en el path clásico del escaneo (`AvisosScanService::run`), pero se manifestaba raramente porque las tandas de 50 con ventana acotada suelen tocar pares únicos. El modo mensual exacerba el problema porque procesa **más candidatos** en cada tanda, y cuando múltiples transcripciones del mismo storage comparten keywords activas, el INSERT bulk rompe.

Sin este fix, el modo mensual no puede completar un mes real (siempre aborta en la primera tanda con duplicados).

## What Changes

- Refactor del matching en `AvisosScanService` para **deduplicar el bulk INSERT** a `keyword_scan_watermarks` antes de enviarlo a PostgreSQL.
- Estrategia de deduplicación: agrupar `bumpSet` por `(keyword_id, storage_provider_id)` y conservar solo la fila con el `scanned_until` más reciente (que es lo que el `ON CONFLICT DO UPDATE` intentaba hacer pero no podía por los duplicados).
- Para el campo `candidates_total` y `hits_total`, **sumar** los valores de las filas duplicadas antes del INSERT (no se pierden hits).
- Sin cambios en la lógica de matching ni en `segment_keyword_hits`. El matching idempotente por UNIQUE triple (transcription, segment, keyword) ya maneja los duplicados de transcripciones correctamente.
- Validación: el comportamiento del modo clásico es idéntico cuando no hay duplicados (camino feliz sin overhead).
- Tests unitarios que reproducen el bug con un dataset sintético que tiene duplicados `(keyword_id, storage_provider_id)` dentro de la misma tanda.

## Capabilities

### Modified Capabilities
- `avisos-scan-configuration`: añadir un requisito sobre la deduplicación del bulk INSERT en `keyword_scan_watermarks`. SHALL agrupar por `(keyword_id, storage_provider_id)` y consolidar antes de ejecutar el INSERT.

## Impact

**Backend:**
- `app/app/Services/Ia/AvisosScanService.php`:
  - Refactor del método que construye `$bumpSet` y el INSERT (líneas ~125-180).
  - Antes del INSERT: agrupar por `(keyword_id, storage_provider_id)`, mantener `scanned_until = MAX(...)`, sumar `candidates_total` y `hits_total`.
- Sin migración de BD.
- Sin cambios en frontend, cache shape, workers supervisord.
- Sin cambios en otros controllers.

**Tests:**
- `app/tests/Unit/AvisosScanServiceBumpDedupeTest.php`:
  - Test con bumpSet que tiene 2 filas con `(94, 12)` → dedupe → INSERT con 1 sola fila.
  - Test con bumpSet con 3 filas del mismo par → suma candidates_total y hits_total.
  - Test con bumpSet sin duplicados → INSERT sin cambios (backward compat).
  - Test de regresión: el bug original del reporte (8+ filas duplicadas del mismo par) ya no rompe.

**Operativo:**
- Riesgo bajo: cambio puntual en un método del service. Tests unitarios validan el comportamiento.
- Backout: `git revert` + reload PHP-FPM. Sin migración ni cambio de cache.

## Non-goals

- No se cambia el matching de keywords ni el shape de `segment_keyword_hits`.
- No se rediseña el schema de `keyword_scan_watermarks`.
- No se cambia el modo mensual (sigue funcionando como antes; este fix lo hace completable).
- No se agregan índices nuevos (no es un problema de performance).
- No se cambia el comportamiento del `ON CONFLICT` clause (sigue siendo GREATEST + SUM).
- No se introducen locks adicionales; el INSERT sigue siendo una sola operación atómica.
