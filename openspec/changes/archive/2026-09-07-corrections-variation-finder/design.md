## Context

El admin tiene un corpus de ~131k menciones de "Abelardo" con ~14 variantes distintas (Pellas, Pella, Pelle, Pellar, etc.). Hoy descubre estas variantes por accidente: aplica una regla, ve que `updated=0` en el cache, va a Mis Avisos a buscar "Abelardo" y nota que el texto sigue mal escrito. Eso es reactivo y costoso (corre reglas que no tocaron nada + tokens de AI Suggest para descubrir lo que SQL descubría en 200ms).

El nuevo flujo es proactivo: admin escribe "Pellas" en un input → ve las 14 variantes con su count y estado de cobertura → crea las que faltan en bulk → aprueba desde Pendientes → apply-retroactive ya tiene qué aplicar.

Variations Finder es **100% SQL**. No consume tokens de IA. Es complementario a AI Suggest (que es LLM-driven y global) y a AI Context-Aware (que es LLM-driven por ejemplo).

## Goals / Non-Goals

**Goals:**
- Endpoint POST que devuelve variantes agrupadas con cobertura (aprobada / pendiente / sin regla).
- UI tab nueva con tabla + acciones por fila + bulk create.
- Performance acotada (cap 10k matches para all-time, paginación vía `next_since`).
- 0 tokens de IA consumidos.
- Sin breaking changes en APIs existentes.

**Non-Goals:**
- Sin matching fonético ni fuzzy (Levenshtein, soundex). Las variantes son literales con ventana de contexto.
- Sin reemplazar AI Suggest.
- Sin integración con apply-retroactive (después de crear reglas pending, el admin sigue el flujo bulk moderation actual).
- Sin tocar el motor de matching de `applyText()`.
- Sin login flows especiales — usa el middleware admin existente.

## Decisions

### 1. Extracción de variante con ventana de contexto vía PostgreSQL SUBSTRING

```sql
SELECT
  id,
  substring(text FROM greatest(1, position('Pellas' IN lower(text)) - 25) 
                FOR position('Pellas' IN lower(text)) + 25 + length('Pellas'))
                AS variant,
  text AS example_text,
  created_at
FROM transcription_segments
WHERE text ILIKE '%Pellas%'
  AND created_at >= '2026-09-06 20:00:00'
LIMIT 10000;
```

PostgreSQL maneja `substring(text FROM ...)` con offsets calculados vía `position()`. La ventana es asimétrica: 25 chars a la izquierda + longitud de la palabra + 25 a la derecha. Esto captura suficiente contexto para distinguir "Abelardo de las Pellas" de "Abelardo Las Pellas".

**Rationale:** SQL nativo es ~1000× más rápido que procesar 131k filas en PHP. El cap LIMIT 10000 evita que un scope "all" colapse la BD.

**Alternatives considered:**
- `tsvector` + full-text search: más infraestructura (migration + GIN index), overkill para esta UI.
- LIKE + procesamiento PHP: viable pero lento en PHP y usa más memoria.
- Levenshtein en SQL (pg_trgm): descartado en non-goals, pero queda como follow-up.

### 2. Normalización en backend (no en SQL)

```php
$normalized = preg_replace('/\s+/', ' ', strtolower(trim($variant)));
$normalized = preg_replace('/[^\wáéíóúñ\s]/', '', $normalized); // collapse punctuation
```

**Rationale:** PostgreSQL tiene `lower()` y `regexp_replace()` pero la combinación de trim + collapse whitespace + collapse punctuation es más fácil de leer en PHP. Cada variante se normaliza una sola vez al cargarla.

### 3. Agrupación en PHP, no en SQL

```php
$grouped = [];
foreach ($matches as $row) {
    $key = normalize($row['variant']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'variant' => $row['variant'],
            'count' => 0,
            'example_segment_id' => $row['id'],
            'example_text' => $row['example_text'],
        ];
    }
    $grouped[$key]['count']++;
}
uasort($grouped, fn($a, $b) => $b['count'] <=> $a['count']);
return array_slice($grouped, 0, $limit);
```

**Rationale:** 10k iteraciones PHP es < 200ms. Más fácil de testear y de extender (p.ej. agregar regex de coalescencia después). SQL `GROUP BY` requeriría normalizar también ahí, lo cual Postgres hace con `regexp_replace` pero es menos legible.

### 4. Cross-reference con rules existentes vía pre-carga

```php
$existingRules = Correction::whereIn('wrong_normalized', array_keys($grouped))
    ->get(['id', 'wrong_normalized', 'status'])
    ->keyBy('wrong_normalized');

foreach ($grouped as $key => &$row) {
    $rule = $existingRules[$key] ?? null;
    $row['is_approved_rule'] = $rule && $rule->status === 'approved';
    $row['is_pending_rule'] = $rule && $rule->status === 'pending';
    $row['existing_rule_id'] = $rule?->id;
}
```

**Rationale:** Una sola query `whereIn` (no N+1). Mantiene el endpoint <300ms incluso con 100 variants × 1 query.

### 5. Bulk create en transacción

```php
DB::transaction(function () use ($rows, $correct, $adminId) {
    foreach ($rows as $row) {
        Correction::create([
            'wrong_text' => $row['variant'],
            'wrong_normalized' => normalize($row['variant']),
            'correct_text' => $correct,
            'status' => 'pending',
            'proposed_by' => $adminId,
            'source' => 'variation-finder-' . now()->format('Y-m-d'),
            'risk_level' => 'low',
        ]);
    }
});
```

**Rationale:** Si una variante ya existe (race condition con otro admin), abortar todo y devolver el nombre conflictivo. Más seguro que crear reglas huérfanas que después habría que limpiar.

### 6. UI: tab nueva en la barra existente (entre AI Suggest y AI Suggest Results)

Sigue el patrón visual de los otros tabs. No requiere layout nuevo.

```
┌─ Variation Finder ────────────────────────────────────────────┐
│  Palabra: [Pellas____________]  Scope: [Últimas 8 horas ▾]    │
│                                                              │
│  [Buscar variantes]                                          │
│                                                              │
│  Resultados (12 únicas de 847 matches en 8h)                 │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ ☐ Variante              │ Frec │ Estado      │ Acción │   │
│  ├──────────────────────────────────────────────────────┤   │
│  │ ☐ abelardo de las pellas│  365 │ ✓ Aprobada  │ Ver    │   │
│  │ ☐ abelardo de las pella │ 1094 │ Pendiente   │ Ver    │   │
│  │ ☐ abelardo las pellas   │    6 │ Sin regla   │ Crear  │   │
│  │ ...                                                     │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  [Crear corrección para 8 seleccionadas]                     │
└──────────────────────────────────────────────────────────────┘
```

Modal de bulk create:
```
┌─ Crear 8 correcciones ───────────────────────────────────────┐
│  Correcto (común a todas):                                  │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ Abelardo de la Espriella                            │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
│  Wrong text por variante:                                    │
│   • Abelardo Las Pella                                       │
│   • Abelardo Las Pellas                                      │
│   • Abelardo de la Pella                                     │
│   • ...                                                      │
│                                                              │
│  [Cancelar]                              [Crear pendientes]  │
└──────────────────────────────────────────────────────────────┘
```

### 7. Source tag `variation-finder-YYYY-MM-DD`

Distinguir las reglas creadas por esta herramienta de las manuales o de AI Suggest para auditoría y para el filtro "Origen" del tab Aprobadas que ya existe.

## Risks / Trade-offs

- **Performance con `text ILIKE '%pattern%'`**: requiere full-scan si no hay índice trigram. Para word "Pellas" en el corpus completo, ~131k matches × full-scan de 6.8M filas puede tardar 5-10 segundos. **Mitigación**: el cap 10000 + `truncated: true` + `next_since` para paginar. Si esto se vuelve un problema, el follow-up es crear índice `pg_trgm` (migration ligera).
- **Falsos positivos**: la ventana puede capturar variantes que NO son del mismo nombre ("Belardo de las Pellas" se agrupa con "Abelardo de las Pellas" porque la ventana de 25 chars incluye palabras distintas). **Mitigación**: el admin ve el `example_text` completo y filtra mentalmente; el modal muestra los 3 ejemplos para confirmar.
- **Race condition en bulk create**: dos admins creando la misma variante simultáneamente → una de las dos falla con duplicate normalized. Devolver HTTP 422 con el nombre conflictivo es la respuesta correcta.

## Migration Plan

Sin migración de BD. Rollout:
1. **Pre-deploy**: leer el código del tab Aprobadas existente para reusar el patrón visual (mismo CSS, mismos estilos).
2. **Deploy**: mergear + deploy.
3. **Verificación manual post-deploy**:
   - Buscar "Pellas" con scope 8h → ver ~12 variantes con sus frecuencias.
   - Click "Crear" en una sin regla → modal abre con wrong pre-llenado.
   - Crear 3 reglas bulk → confirmar que aparecen en Pendientes.
4. **No rollback window needed**: si falla, las rules creadas quedan en pending (no approved) y el admin puede rechazarlas.

## Open Questions

- **¿La variante se guarda tal cual del segmento o se normaliza para `wrong_text`?** Decisión: guardar la versión que aparece en el segmento (preserva mayúsculas/acentos) y normalizar SOLO para `wrong_normalized` (igual que el flujo admin-manual). Coherente con el resto del módulo.
- **¿Exponer este endpoint como API pública?** Decisión: NO, requiere auth admin. Mismo middleware que el resto de `/ia/correcciones/*`.
