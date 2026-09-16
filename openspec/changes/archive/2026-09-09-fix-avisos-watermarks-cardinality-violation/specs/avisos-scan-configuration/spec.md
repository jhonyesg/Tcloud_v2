## ADDED Requirements

### Requirement: Bulk INSERT de keyword_scan_watermarks deduplica antes de ejecutar

Cuando el matching construye el array `$bumpSet` para escribir en `keyword_scan_watermarks`, SHALL deduplicar las filas que comparten el mismo `(keyword_id, storage_provider_id)` antes de ejecutar el INSERT. La deduplicación SHALL consolidar:

- `scanned_until = MAX(scanned_until)` de las filas duplicadas (la más reciente).
- `candidates_total = SUM(candidates_total)` de las filas duplicadas (acumulado).
- `hits_total = SUM(hits_total)` de las filas duplicadas (acumulado).
- `last_scanned_at = MAX(last_scanned_at)` de las filas duplicadas.
- El `last_scan_run_id` puede ser cualquiera de las filas (no importa cuál; el UPDATE posterior sobrescribe con el runId actual).

Después de deduplicar, el INSERT SHALL ejecutarse UNA sola vez por par `(keyword_id, storage_provider_id)` sin causar `Cardinality violation`.

#### Scenario: 8 transcripciones del mismo par se consolidan en 1 fila
- **WHEN** `$bumpSet` tiene 8 filas con `keyword_id=94, storage_provider_id=12` y distintos `scanned_until` (de `2026-07-11 05:50:18` a `2026-07-11 08:15:49`)
- **THEN** después de dedupe hay exactamente 1 fila con `keyword_id=94, storage_provider_id=12`, `scanned_until = '2026-07-11 08:15:49'` (MAX), `candidates_total = 8` (SUM), `hits_total = SUM de hits`
- **AND** el INSERT no genera `Cardinality violation`

#### Scenario: Sin duplicados el INSERT no cambia
- **WHEN** `$bumpSet` tiene 50 filas con pares `(keyword_id, storage_provider_id)` todos distintos
- **THEN** el INSERT contiene las 50 filas tal cual (cero deduplicación)
- **AND** el comportamiento es idéntico al estado actual (backward compat con el camino feliz)

#### Scenario: Hits se suman correctamente
- **WHEN** `$bumpSet` tiene 3 filas con el mismo `(keyword_id=10, storage_provider_id=5)` y `hits_total = [2, 0, 5]`
- **THEN** la fila deduplicada tiene `hits_total = 7` (suma de los 3)
- **AND** el contador `hits_new` en el cache state suma correctamente los hits originales (no se pierden)

#### Scenario: Modo clásico no se afecta
- **WHEN** un scan clásico (con `preset='24h'`) procesa 50 candidatos todos con pares únicos
- **THEN** el path funciona idéntico al estado actual (sin overhead, sin cambios visibles)
- **AND** un scan clásico con duplicados (caso raro antes) ahora funciona correctamente sin romper
