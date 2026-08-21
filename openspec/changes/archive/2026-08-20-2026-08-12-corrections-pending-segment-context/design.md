# Design: Contexto del segmento en correcciones pendientes

## Overview

Esta change añade **contexto del segmento** a la lista de correcciones pendientes y aprobadas. El admin pasa de aprobar a ciegas a ver el fragmento de la transcripción donde aparece el `wrong_text`, con un modal opcional para ver el segmento completo cuando necesita más detalle.

El cambio es **principalmente de UX** con dos toques pequeños de backend (poblar `source_segment_id` en dos producers y exponer un endpoint de detalle).

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Productor (Miner / AI Suggest)                                              │
│   propose(wrong, correct)                                                   │
│            │                                                                │
│            ▼                                                                │
│   ┌────────────────────┐                                                    │
│   │ SELECT id FROM     │  ← +1 query por candidato                          │
│   │ segments WHERE     │                                                    │
│   │ text_raw ILIKE %w% │                                                    │
│   │ ORDER BY date DESC │                                                    │
│   │ LIMIT 1            │                                                    │
│   └────────────────────┘                                                    │
│            │                                                                │
│            ▼                                                                │
│   propose(wrong, correct, segmentId)  ← nuevo argumento                    │
└──────────────────────────────────────────────────────────────────────────────┘
            │
            ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│ /ia/correcciones                                                              │
│                                                                              │
│   ┌──┬────────┬────────┬─────────────────────┬───────┬─────┐                 │
│   │☐ │Original│Correcc.│ Contexto            │ Prop. │ Fech│                 │
│   ├──┼────────┼────────┼─────────────────────┼───────┼─────┤                 │
│   │☐ │ io     │ Io  ✗  │ …al Tio Nacho Eng…  │ mine  │ ago │                 │
│   │☐ │ of     │ de     │ …top of the morni…  │ mine  │ ago │                 │
│   └──┴────────┴────────┴─────────────────────┴───────┴─────┘                 │
│       Admin hace click en el snippet ▼                                       │
│                                                                              │
│   ┌──────────────────────────────────────┐                                   │
│   │ ⏱ 00:12:34 → 00:12:51 · Segmento #5 │                                   │
│   │ 📄 archivo.mp3                       │                                   │
│   │                                      │                                   │
│   │ Original:                            │                                   │
│   │  "…presentamos al [Io] Nacho…"       │                                   │
│   │                                      │                                   │
│   │ Corregido:                           │                                   │
│   │  "…presentamos al [Tio] Nacho…"      │                                   │
│   │                                      │                                   │
│   │ [Ver transcripción completa]         │                                   │
│   └──────────────────────────────────────┘                                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

## Data Model

**Sin cambios de schema.** `corrections.source_segment_id` ya es `nullable foreignId` con cascade a `transcription_segments`.

TranscriptionSegment (existente, lo que usamos):
- `id`, `transcription_id`, `segment_index`
- `start_seconds`, `end_seconds`
- `text_raw` (original del transcriptor)
- `text` (post-correcciones)

## Backend

### Cambio 1a: EnEsMixMiner

Modificar el método `runMining()` (o donde se itera candidatos) para hacer lookup antes del `propose()`:

```php
foreach ($candidates as $candidate) {
    // Idempotencia: si ya existe pending con mismo wrong_normalized, skip.
    if (Correction::pending()->where('wrong_normalized', ...)->exists()) {
        $skipped++;
        continue;
    }

    // NUEVO: buscar el segmento más reciente donde aparece el wrong_text.
    $segmentId = DB::table('transcription_segments')
        ->where('text_raw', 'ILIKE', '%' . $this->escapeIlike($candidate['wrong']) . '%')
        ->orderByDesc('created_at')
        ->value('id');

    $correction = $this->propose($by, $candidate['wrong'], $candidate['correct'], $segmentId);
    $correction->source = $source;
    $correction->save();
    $inserted++;
}
```

Helper `escapeIlike(string $s): string` escapa `%`, `_`, `\` para ILIKE. La miner corre en Postgres; en SQLite el operador es `LIKE` case-insensitive por default. Mantener consistencia.

Performance: 19.6M segmentos pero el lookup es por índice `text_raw` ILIKE con trigramas si existe, sino seq scan parcial. Si miner genera 50 candidatos, son 50 queries. Aceptable.

Edge case: si `wrong` tiene caracteres especiales SQL, usar parámetro bindeado. NO concatenar.

### Cambio 1b: LlmCorrectionSuggester

Idéntico lookup antes del `propose()`.

### Cambio 2: endpoint `GET /correcciones/{id}/source-segment`

```php
public function sourceSegment(int $id)
{
    $correction = Correction::with('sourceSegment.transcription')->findOrFail($id);
    $segment = $correction->sourceSegment;

    if (!$segment) {
        return response()->json(['error' => 'no_segment'], 404);
    }

    return response()->json([
        'segment' => [
            'id' => $segment->id,
            'segment_index' => $segment->segment_index,
            'start_seconds' => $segment->start_seconds,
            'end_seconds' => $segment->end_seconds,
            'text_raw' => $segment->text_raw,
            'text' => $segment->text,
        ],
        'transcription' => $segment->transcription ? [
            'id' => $segment->transcription->id,
            'file_name' => $segment->transcription->file_name ?? basename($segment->transcription->path ?? ''),
        ] : null,
    ]);
}
```

Auth: dentro del grupo `Route::middleware(['auth', 'admin'])->prefix('ia')` existente.

Ruta:
```php
Route::get('/correcciones/{id}/source-segment', [CorreccionesController::class, 'sourceSegment'])->whereNumber('id');
```

### Cambio 3: hacer que la eager-load exponga el transcription

`sourceSegment()` en el modelo ya existe. Pero necesitamos `sourceSegment.transcription`. El eager-load actual es `->with('sourceSegment')`. Cambiar a `->with('sourceSegment.transcription')` para evitar N+1.

Mismo cambio en el método `pending()` del controller (línea 100):
```php
$pending = Correction::pending()
    ->with('proposedBy', 'sourceSegment.transcription')
    ->latest()
    ->get();
```

Y en `approved()` (línea 117) para que el tab Aprobadas también gane la columna.

## Frontend

### Columna "Contexto" en la tabla

Patrón mirror de las otras columnas. Insertar entre `<th>` "Corrección" y "Proponente":

```html
<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">
    Contexto
</th>

<td class="px-4 py-3 text-sm text-slate-600 max-w-md hidden md:table-cell">
    <template x-if="c.source_segment && c.source_segment.text_raw">
        <button @click="openSegmentContext(c)"
                class="text-left hover:bg-slate-100 rounded px-2 py-1 -mx-2 inline-block max-w-full"
                :title="'Click para ver el segmento completo'">
            <span x-html="snippetHtml(c)"></span>
        </button>
    </template>
    <template x-if="!c.source_segment || !c.source_segment.text_raw">
        <span class="text-slate-400 text-xs">—</span>
    </template>
</td>
```

`snippetHtml(c)` — Alpine getter o método:

```js
snippetHtml(c) {
    const raw = c.source_segment?.text_raw || '';
    const wrong = c.wrong_text || '';
    if (!raw) return '';
    if (!wrong || !raw.toLowerCase().includes(wrong.toLowerCase())) {
        // Highlight no encontrado en el texto, mostrar primeros 100 chars tal cual.
        return this.escapeHtml(raw.slice(0, 100)) + (raw.length > 100 ? '…' : '');
    }
    // Centrar el snippet alrededor de wrong_text.
    const idx = raw.toLowerCase().indexOf(wrong.toLowerCase());
    const wrongLen = wrong.length;
    const padding = Math.max(0, Math.floor((100 - wrongLen) / 2));
    const start = Math.max(0, idx - padding);
    const end = Math.min(raw.length, idx + wrongLen + padding);
    const prefix = start > 0 ? '…' : '';
    const suffix = end < raw.length ? '…' : '';
    const before = raw.slice(start, idx);
    const match = raw.slice(idx, idx + wrongLen);
    const after = raw.slice(idx + wrongLen, end);
    return prefix
        + this.escapeHtml(before)
        + '<mark class="bg-red-100 text-red-800 px-0.5 rounded font-semibold">'
        + this.escapeHtml(match)
        + '</mark>'
        + this.escapeHtml(after)
        + suffix;
},

escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
},
```

### Modal "Contexto del segmento"

Patrón mirror del modal "Editar corrección pendiente" y "Eliminar sugerencia". Insertar después del modal Eliminar en lote.

```html
<div x-cloak x-show="segmentContext.open"
     class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
     x-transition>
    <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl"
         @click.away="segmentContext.open = false">
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-800 mb-1">Contexto del segmento</h2>

            <template x-if="segmentContext.loading">
                <div class="py-8 text-center text-slate-400">
                    <i class="fas fa-spinner fa-spin mr-2"></i> Cargando segmento…
                </div>
            </template>

            <template x-if="!segmentContext.loading && segmentContext.data">
                <div class="space-y-4 mt-3">
                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span class="px-2 py-1 bg-slate-100 rounded font-mono">
                            <span x-text="formatHms(segmentContext.data.segment.start_seconds)"></span>
                            →
                            <span x-text="formatHms(segmentContext.data.segment.end_seconds)"></span>
                        </span>
                        <span>Segmento #<span x-text="segmentContext.data.segment.segment_index"></span></span>
                        <template x-if="segmentContext.data.transcription">
                            <a :href="'/ia/api-transcriptor/' + segmentContext.data.transcription.id"
                               class="ml-auto text-brand-600 hover:text-brand-700 hover:underline">
                                <i class="fas fa-external-link-alt mr-1"></i>
                                <span x-text="segmentContext.data.transcription.file_name"></span>
                            </a>
                        </template>
                    </div>

                    <div>
                        <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Original (transcriptor)</label>
                        <p class="text-sm bg-slate-50 rounded-lg px-3 py-2 leading-relaxed"
                           x-html="highlightedRaw()"></p>
                    </div>

                    <template x-if="segmentContext.data.segment.text !== segmentContext.data.segment.text_raw">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase tracking-wide">Corregido (diccionario aplicado)</label>
                            <p class="text-sm bg-emerald-50 rounded-lg px-3 py-2 leading-relaxed"
                               x-html="highlightedText()"></p>
                        </div>
                    </template>
                </div>
            </template>

            <div class="flex gap-3 mt-6">
                <button @click="segmentContext.open = false"
                        class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
```

`highlightedRaw()` y `highlightedText()` son métodos Alpine que retornan el text con `wrong_text` (raw) o `correct_text` (text) envuelto en `<mark>`.

Estado Alpine:
```js
segmentContext: { open: false, loading: false, correction: null, data: null },
```

Handlers:
```js
async openSegmentContext(c) {
    this.segmentContext = { open: true, loading: true, correction: c, data: null };
    try {
        const res = await apiFetch('/ia/correcciones/' + c.id + '/source-segment', {
            headers: { 'Accept': 'application/json' }
        });
        if (res.ok) {
            const data = await res.json();
            this.segmentContext.data = data;
        } else if (res.status === 404) {
            this.segmentContext.data = { notFound: true };
        } else {
            const d = await res.json().catch(() => ({}));
            alert(d.error || 'Error al cargar segmento.');
            this.segmentContext.open = false;
            return;
        }
    } catch (e) {
        alert('Error de red al cargar segmento.');
        this.segmentContext.open = false;
        return;
    }
    this.segmentContext.loading = false;
},

closeSegmentContext() {
    this.segmentContext = { open: false, loading: false, correction: null, data: null };
},

formatHms(seconds) {
    if (!seconds) return '00:00:00';
    const s = Math.floor(seconds);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
},

highlightedRaw() {
    const c = this.segmentContext.correction;
    const seg = this.segmentContext.data?.segment;
    if (!c || !seg) return '';
    return this.highlightInText(seg.text_raw, c.wrong_text, 'red');
},

highlightedText() {
    const c = this.segmentContext.correction;
    const seg = this.segmentContext.data?.segment;
    if (!c || !seg) return '';
    return this.highlightInText(seg.text, c.correct_text, 'green');
},

highlightInText(text, target, color) {
    if (!text || !target) return this.escapeHtml(text || '');
    const idx = text.toLowerCase().indexOf(target.toLowerCase());
    if (idx === -1) return this.escapeHtml(text);
    const colors = {
        red:    'bg-red-100 text-red-800 px-0.5 rounded font-semibold',
        green:  'bg-emerald-100 text-emerald-800 px-0.5 rounded font-semibold',
    };
    return this.escapeHtml(text.slice(0, idx))
        + `<mark class="${colors[color] || colors.red}">`
        + this.escapeHtml(text.slice(idx, idx + target.length))
        + '</mark>'
        + this.escapeHtml(text.slice(idx + target.length));
},
```

### Tab Aprobadas también gana la columna

El mismo `<th>` y `<td>` se replica en el `<template x-for="c in approvedFiltered">` (línea ~365). La eager-load ya viene del método `approved()` del controller (cambio menor: añadir `.transcription`).

## Tour guiado

Aplica `interactive_tours_must_include_new_features` si existe tour. (Verificar — el módulo no tenía tour al momento del change `corrections-pending-edit-delete`; sigue sin tenerlo.)

## Archivos a modificar

| Archivo | Cambio |
|---|---|
| `app/app/Services/Ia/EnEsMixMiner.php` | Lookup de segmento antes de `propose()` |
| `app/app/Services/Ia/LlmCorrectionSuggester.php` | Lookup de segmento antes de `propose()` |
| `app/app/Http/Controllers/Ia/CorreccionesController.php` | Nuevo método `sourceSegment()` + eager-load `.transcription` en `pending()` y `approved()` |
| `app/routes/web.php` | Ruta `GET /correcciones/{id}/source-segment` |
| `app/resources/views/ia/correcciones/index.blade.php` | Columna "Contexto" en pendientes y aprobadas, modal, métodos Alpine |

## Riesgos y monitoreo

| Riesgo | Mitigación |
|---|---|
| Lookup ILIKE '%foo%' hace seq scan | 19.6M filas pero miner corre batch, no hot path. Si duele, agregar índice GIN trigram en `text_raw` (futuro). |
| `wrong_text` no aparece en `text_raw` (corrección más amplia que la sugerencia) | Mostrar text_raw sin highlight, tooltip explicativo. No fallar. |
| Miner más lento post-deploy | Medir con log de duración antes/después. Si >2x, considerar índice. |
| Source_segment_id NULL en correcciones legacy | Columna muestra "—". No rompe nada. |
| Modal pesado con text_raw largo | `text_raw` puede tener 500+ chars. CSS `max-height: 60vh; overflow-y: auto` en el `<p>` interno. |
