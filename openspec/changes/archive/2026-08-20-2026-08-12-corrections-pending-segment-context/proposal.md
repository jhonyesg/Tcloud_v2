# Change: Contexto del segmento en correcciones pendientes

## Why

El admin revisa las sugerencias en `/ia/correcciones` viendo solo `wrong_text` y `correct_text`. No tiene forma de saber **en qué parte de la transcripción aparece el `wrong_text`**, así que aprueba o rechaza a ciegas.

Esto causa tres problemas concretos:

1. **Falsos positivos aprobados**: una sugerencia como `of → de` puede parecer razonable aislada pero romper el sentido si el contexto era "of love" o "top of the morning". Sin contexto, el admin no lo detecta.
2. **Falsos negativos rechazados**: una sugerencia como `ist → ist` puede verse como ruido pero ser correcta en el dialecto/transcripción de ese audio específico.
3. **Tiempo perdido**: cada aprobación se hace "por fe". El admin acaba abriendo `/ia/api-transcriptor` y buscando el archivo para verificar, cuando esa info podría estar en la misma pantalla.

El campo `source_segment_id` ya existe en el schema desde la migración `2026_07_06_170008_create_corrections_table.php` (FK a `transcription_segments`), el endpoint `/pending` ya eager-loads `sourceSegment`, y los segmentos tienen `text_raw`, `text`, `start_seconds`, `end_seconds`, `transcription_id`. **El dato no se está poblando** y la UI no lo renderiza.

Estado actual verificado en BD:
- 2997 approved, 11 rejected, 2 pending → 0 con `source_segment_id`
- 19.6M segmentos disponibles para enlazar
- Ningún producer (EnEsMixMiner, LlmCorrectionSuggester) pasa `segmentId` a `propose()`

## What Changes

### 1. Backend: poblar `source_segment_id` en producers

#### 1a. `EnEsMixMiner` (correcciones-miner)
Cuando genera cada candidato `wrong → correct`, antes de llamar a `propose()`, hacer:

```sql
SELECT id FROM transcription_segments
WHERE text_raw ILIKE '%' || wrong || '%'
ORDER BY created_at DESC
LIMIT 1
```

Pasar el id a `propose($by, $wrong, $correct, $segmentId)`. Si no hay match (caso raro: el segmento fue purgado), pasar `null` y seguir.

Costo: +1 SELECT por candidato. El miner produce ~10-50 candidatos por corrida batch → +50 queries (~200ms total), no impacta hot path.

#### 1b. `LlmCorrectionSuggester` (correcciones-ai-suggest)
Mismo lookup antes de cada `propose()`. El suggester ya hace N queries al LLM y al BD; +1 SELECT es marginal.

### 2. Backend: endpoint para detalle de segmento

`GET /ia/correcciones/{id}/source-segment`

Devuelve:
```json
{
  "segment": {
    "id": 12345,
    "segment_index": 5,
    "start_seconds": 754.2,
    "end_seconds": 771.8,
    "text_raw": "...presentamos al Tio Nacho Engrosador...",
    "text":     "...presentamos al Tio Nacho Engrosador..."
  },
  "transcription": {
    "id": 8421,
    "file_name": "archivo.mp3"
  }
}
```

Si `correction.source_segment_id` es null → 404 con `{error: "no_segment"}`.

Solo accesible para admin autenticado (mismo middleware del grupo).

### 3. UI: nueva columna "Contexto" en la tabla de pendientes

Insertar entre "Corrección" y "Proponente":

```html
<th class="... hidden md:table-cell">Contexto</th>

<td class="px-4 py-3 text-sm text-slate-600 max-w-md hidden md:table-cell">
  <template x-if="c.source_segment">
    <button @click="openSegmentContext(c)"
            class="text-left hover:bg-slate-100 rounded px-2 py-1 -mx-2 truncate max-w-full inline-block"
            :title="'Click para ver el segmento completo'">
      <span x-html="snippetHtml(c)"></span>
    </button>
  </template>
  <template x-if="!c.source_segment">
    <span class="text-slate-400 text-xs">—</span>
  </template>
</td>
```

`snippetHtml(c)`:
- Toma `c.source_segment.text_raw`
- Trunca a ~100 chars centrados en `c.wrong_text` (con elipsis `…` si hay más)
- Hace escape HTML
- Reemplaza `c.wrong_text` por `<mark class="bg-red-100 text-red-800 px-0.5 rounded">…</mark>`
- Devuelve el string (Alpine lo inyecta vía `x-html`)

Si `wrong_text` no aparece en `text_raw` (caso raro: correcciones generadas con texto ligeramente distinto al original), muestra el text_raw completo sin highlight y un tooltip "La corrección no aparece textualmente en este segmento".

### 4. UI: modal "Contexto del segmento"

Estado Alpine nuevo: `segmentContext: { open: false, loading: false, data: null, correction: null }`

Handler `openSegmentContext(c)`:
- Cierra cualquier modal abierto
- Setea `segmentContext = { open: true, loading: true, correction: c, data: null }`
- Hace fetch a `/ia/correcciones/{id}/source-segment`
- Renderiza:
  - Header: `⏱ 00:12:34 → 00:12:51 · Segmento #5`
  - Subheader: `📄 archivo.mp3` (link a `/ia/api-transcriptor/{transcription_id}`)
  - Sección **Original**: `text_raw` con `wrong_text` en `<mark class="bg-red-100">`
  - Sección **Corregido** (si text ≠ text_raw): `text` con `correct_text` en `<mark class="bg-green-100">`
  - Botón "Ver transcripción completa" → link externo

### 5. UI: tabla de Aprobadas — también gana la columna

El mismo cambio se aplica al tab Aprobadas para coherencia. Las correcciones nuevas ya vendrán con `source_segment_id`. Las legacy (sin segment_id) muestran "—".

### 6. Datos legacy

Las 2997 correcciones aprobadas existentes NO se reprocesan (decisión consciente). Las nuevas (post-deploy) sí tendrán contexto. Backfill opcional mediante comando artisan separado en un change futuro si el admin lo pide.

## Non-Goals

- **No** se agregan segmentos vecinos en el modal (decidido: modal básico).
- **No** se modifica el schema. `source_segment_id` ya existe.
- **No** se hace backfill automático del histórico.
- **No** se cambia la lógica de aprobación/rechazo. Solo se agrega contexto visual.
- **No** se muestran TODAS las apariciones del `wrong_text` (decidido: solo el segmento más reciente).
