## Context

Reproducido durante la validación del change `fix-avisos-scan-by-months-no-saturation`. El worker procesó mes `2026-07` con 50 candidatos, llegó al matching, y el bulk INSERT a `keyword_scan_watermarks` falló con `Cardinality violation`. Log del incidente:

```
[2026-09-09] INSERT INTO keyword_scan_watermarks
  VALUES (94,12,'2026-07-11 05:50:18',...)
         (95,12,'2026-07-11 05:50:18',...)
         (117,12,'2026-07-11 05:50:18',...)
         (94,12,'2026-07-11 06:05:11',...)   ← dup (94,12)
         (95,12,'2026-07-11 06:05:11',...)
         ...
ON CONFLICT (keyword_id, storage_provider_id) DO UPDATE SET ...
→ ERROR: ON CONFLICT DO UPDATE command cannot affect row a second time
```

El bug es pre-existente en `AvisosScanService::run()` (el path clásico). Se manifiesta raramente porque con ventana temporal corta y batch de 50, la probabilidad de colisión `(keyword_id, storage_provider_id)` dentro del mismo batch es baja. El modo mensual exacerba el problema porque procesa más candidatos por mes, encontrando más colisiones.

## Goals / Non-Goals

**Goals:**
- Deduplicar `$bumpSet` antes del INSERT.
- Consolidar `scanned_until = MAX`, `candidates_total = SUM`, `hits_total = SUM`.
- Sin cambio de comportamiento cuando no hay duplicados (camino feliz).
- Tests unitarios con dataset sintético que reproduce el bug.

**Non-Goals:**
- No se cambia el matching de keywords.
- No se rediseña el schema.
- No se cambian los índices.
- No se introducen locks adicionales.

## Decisions

### 1. Deduplicación en PHP, no en SQL

**Decisión**: dedupe en PHP con `array_reduce` o loop simple antes de enviar a SQL. PostgreSQL no tiene UPSERT que dedupe dentro del batch.

**Rationale**: el costo es trivial (50-100 filas por tanda) y mantiene la lógica de consolidación visible para debugging. La alternativa (`WITH ... AS (DELETE FROM keyword_scan_watermarks WHERE ...)` antes del INSERT) agrega complejidad innecesaria.

**Alternativa descartada**: `INSERT ... ON CONFLICT WHERE excluded.scanned_until > keyword_scan_watermarks.scanned_until` con un GROUP BY previo. Descartado por la complejidad del CTE vs un simple loop de 50 filas.

### 2. SUM(candidates_total) y SUM(hits_total)

**Decisión**: cuando hay duplicados, los contadores se SUMAN. No se descarta el hit de una transcripción anterior porque ya fue persistido en `segment_keyword_hits` (que es UNIQUE triple). El `hits_total` en `keyword_scan_watermarks` es un agregado de cobertura, no un set de hits.

**Rationale**: si la keyword `K` matcheó en 5 transcripciones del storage `S` en el mismo mes, queremos `hits_total = 5` para reflejar la cobertura real. Perder el conteo de hits en duplicados subestima la cobertura.

### 3. MAX(scanned_until), no AVG ni MIN

**Decisión**: usar `MAX(scanned_until)` que es lo que el `ON CONFLICT` intentaba hacer con `GREATEST(excluded.scanned_until, keyword_scan_watermarks.scanned_until)`. El más reciente siempre gana.

**Rationale**: el watermark marca "hasta dónde hemos procesado"; el más reciente es el que representa el estado actual del catch-up.

## Risks / Trade-offs

- **Performance**: dedupe de 50 filas toma <1ms. Imperceptible.
- **Atomicidad**: el INSERT sigue siendo atómico (un solo statement). Si falla, no se aplica nada (igual que antes).
- **Compatibilidad**: si por algún caller externo se esperaba que el INSERT tuviera N filas para N transcripciones, ahora tendrá menos. Pero `$bumpSet` es interno del service, no API pública.

## Migration Plan

1. Merge.
2. Reload PHP-FPM.
3. Sin migration de BD, sin restart de workers.
4. Verificación E2E: lanzar el scan mensual con Playwright; el worker debe procesar mes completo sin error `Cardinality violation`.
5. Rollback: `git revert` + reload.

## Open Questions

Ninguna. La solución es mecánica.
