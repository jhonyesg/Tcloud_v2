## Context

Estado actual (ver `proposal.md` para motivación completa):

- 6 reglas `of X → de X` sin artículo viven en `corrections` con `status='approved'` y fuente `auto-cycle-*` o `ai-suggest-*`. Sobre-corregiren inglés embebido: títulos de canciones (`Power of Love`, `Glory of Love`), nombres propios institucionales (`Solidarity of Colombia`), frases hechas en inglés (`of security`, `of melanoma`).
- 2 reglas `of cambio → de el cambio` y `of agua → de la agua` tienen género mal: el artículo contracto correcto es `del` en ambos casos (singular masculino "cambio", y la regla histórica del español "el agua" → "del agua").
- La columna `corrections.status` ya soporta `rejected` con `rejected_reason` (verificado en `app/app/Models/Correction.php` y el flujo de moderación bulk en `/ia/correcciones`).

Restricciones del proyecto:

- No tocar `transcription_segments` en este change (alcance aprobado: solo `corrections`).
- Queries a `transcription_segments` son pesadas (~23.7M filas); ningún query nuevo sobre esa tabla.
- PostgreSQL con timezone UTC; timestamps en `created_at` sin zona.

## Goals / Non-Goals

**Goals:**

- Sacar del flujo de aplicación las 6 reglas sin artículo que producen espanglish audible.
- Corregir el `correct_text` de las 2 reglas con género mal para que el próximo segmento se aplique correctamente.
- Mantener la auditoría: las 6 reglas rechazadas quedan con `rejected_reason` trazable.

**Non-Goals:**

- No se re-aplica retroactivamente. Las 3.381 aplicaciones históricas persisten en `transcription_segments.text` hasta que se corra un retroactivo futuro (que las sobre-escribirá al buen valor).
- No se modifican `KNOWN_EN_ES_MAPPINGS` ni `EnEsMixMiner`; el bug no vino del miner sino del auto-cycle / AI suggest.
- No se eliminan las reglas con `applies_count > 1000` sin revisión; en este lote solo se incluye `of colombia` (1.570 apps) por estar en el mismo patrón sobre-agresivo, pero se documenta la métrica.

## Decisions

### D1. Marcar `status='rejected'` en vez de DELETE físico

**Decisión**: `UPDATE corrections SET status='rejected', rejected_reason='overreach-of-mapping-2026-08-15', updated_at=NOW() WHERE id IN (...)`.

**Rationale**: la tabla `corrections` es el log de moderación; el admin revisa Aprobadas/Rechazadas en `/ia/correcciones`. Borrar físicamente pierde auditoría. El matcher ya filtra por `status='approved'` (`Correction::approved()`), así que la regla queda inactiva inmediatamente.

**Alternativas consideradas**:

- **A. DELETE físico**: borra la fila. Más limpio pero pierde `applies_count` y `source` que documentan el bug. Descartado.
- **B. `status='archived'`**: convención usada en otros cambios para salidas de diccionario, pero el matcher activo solo consulta `approved`. Descartado por uniformidad con el flujo de moderación existente.

### D2. `rejected_reason` con timestamp para trazabilidad

**Decisión**: `rejected_reason='overreach-of-mapping-2026-08-15'` (fija, sin timestamp dinámico).

**Rationale**: el formato ya se usa en moderación manual (ej: "ya corregido por upstream"); un tag estable permite grep en logs y SQL. El `updated_at` ya marca el cuándo.

**Alternativas consideradas**:

- **A. Mensaje libre largo**: añade ruido en la UI. Descartado.
- **B. JSON con metadata**: sobreingeniería para 8 filas. Descartado.

### D3. Fix de `correct_text` sin cambiar status

**Decisión**: `UPDATE corrections SET correct_text='del cambio', updated_at=NOW() WHERE id=253` (y análogo para id=261).

**Rationale**: las reglas de género están conceptualmente bien (`of X` → `de[l] X`), solo el artículo está mal. Corregir el destino y mantener `approved` preserva el matching para futuros segmentos.

**Alternativas**:

- **A. Eliminar y re-crear la regla**: cambiaría `id` y rompería referencias internas. Descartado.
- **B. Marcar como `rejected` y crear nuevas reglas approved**: duplica filas. Descartado.

### D4. Sin retroactivo en este change

**Decisión**: no se ejecuta `corrections:apply-retroactive` ni se hace UPDATE masivo sobre `transcription_segments`. Las 3.381 apps históricas se preservan hasta un retroactivo futuro.

**Rationale**: el alcance aprobado por el usuario fue explícitamente "toda la familia rota" SIN retroactivo. Forzar re-aplicación revertiría también correcciones correctas si una regla tiene overlap; necesita un comando dedicado de revert-by-correction-id (out of scope).

**Nota operacional**: cuando se corra el próximo retroactivo (manual desde `/ia/correcciones`), las 6 reglas eliminadas ya no aplicarán y las 2 corregidas aplicarán el `correct_text` bueno. Las apps históricas se sobre-escriben automáticamente.

## Risks / Trade-offs

- **R1. Las 3.381 apps históricas en `text` siguen ahí hasta el próximo retroactivo** → Mitigación: documentar en `proposal.md` que se sobre-escribirán solas; UI muestra las reglas eliminadas en "Rechazadas" para auditoría.
- **R2. `of colombia` (1.570 apps) es el cambio de mayor superficie** → Mitigación: incluido en el alcance aprobado; si el admin nota falsos positivos en otros "of colombia" puede re-aprobar manualmente desde la UI de Rechazadas.
- **R3. Otros patrones "of X" no incluidos aquí** → Mitigación: la query SQL del task 5.1 lista TODAS las reglas `of %` con `applies_count > 0` para que el admin las revise; este change solo toca las 8 confirmadas.

## Migration Plan

Sin esquema. Sin deploy escalonado.

**Pasos de deploy**:

1. Backup lógico de las 8 filas (`SELECT ... INTO _temp`).
2. Ejecutar los UPDATEs (ver `tasks.md`).
3. Verificar con la misma query de auditoría que las 8 filas están en el estado esperado.
4. Sin paso de rollback dedicado: si el admin quiere re-aprobar alguna, lo hace desde la UI de Rechazadas con un click.

**Rollback**: `UPDATE corrections SET status='approved', rejected_reason=NULL WHERE id IN (2990, 2991, 2992, 3003, 2263, 3094)` + re-corregir las 2 con género original. Operativo desde SQL, sin deploy.

## Open Questions

Ninguna. Las decisiones tomadas son reversibles vía SQL directo y la trazabilidad queda en `rejected_reason`.