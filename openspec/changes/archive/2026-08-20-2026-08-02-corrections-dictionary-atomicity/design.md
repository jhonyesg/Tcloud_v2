# Design: Dictionary atomicity — pruning, effectiveness UI y prompts atómicos

## Architectural overview

Tres cambios ortogonales que se entregan juntos porque comparten modelo mental (effectiveness vs noise) y atómicos a nivel de release:

```
┌─────────────────────────────────────────────────────────────────────┐
│  UI layer (Blade + Alpine)                                          │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────┐ │
│  │ Approved tab:      │  │ Atomicity panel    │  │ Audit tab/widg │ │
│  │ - sort aplica.     │  │ - unigrams/bigram  │  │ - top tokens   │ │
│  │ - filter inactive  │  │ - bulk add         │  │ - stats        │ │
│  │ - bulk cleanup     │  │                    │  │                │ │
│  │ - risk_level badge │  │                    │  │                │ │
│  └────────┬───────────┘  └─────────┬──────────┘  └───────┬────────┘ │
│           │ endpoints              │ endpoints           │          │
│  ┌────────┴─────────────────────────┴─────────────────────┴────────┐ │
│  │ NEW: "Contexto sensible" tab (risk=high + risk=medium)          │ │
│  │  - Auditor suggestions apply                                     │ │
│  │  - Override risk per row                                         │ │
│  └────────┬───────────────────────────────────────────────────────┘ │
│           │ endpoint                                                 │
└───────────┼─────────────────────────────────────────────────────────┘
            ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Controller (CorreccionesController)                                │
│  +atomicitySuggestions(int $id) → JSON {suggestions: [...]}         │
│  +bulkDestroyInactive(Request) → JSON {destroyed, bulk_action_id}   │
│  +auditReport() → JSON {stats: {...}}                               │
│  +contextAudit() → JSON {suggestions: [{id, risk, reason}, ...]}    │
│  +contextAuditApply(Request) → JSON {updated: N}                    │
│  ~approve()/store() returns {correction, context_warning?}           │
└────────────┬────────────────────────────────────────────────────────┘
             ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Service (CorrectionService + DictionaryAudit + ContextShiftAuditor │
│           + LlmCorrectionSuggester)                                  │
│  - extractAtomicitySuggestions(Correction $c): array                │
│  - bulkDestroyInactive(int $minAgeDays, int $maxCount, User): array │
│  - DictionaryAudit::run(): array  (read-only)                       │
│  - ContextShiftAuditor::audit(): array {id: {risk, reason}}         │
│  - ContextShiftAuditor::applyToDb(bool $dryRun): array              │
│  - LlmCorrectionSuggester::getSystemPrompt(): string  (atomic-first)│
│  - LlmCorrectionSuggester::evaluateCandidate(): array {accept,reason}│
└────────────┬────────────────────────────────────────────────────────┘
             ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Model (Correction) + correction_bulk_actions                       │
│  - NEW column: risk_level ENUM('low','medium','high') DEFAULT 'low' │
│  - NEW scope: safe() → excludes risk='high'                          │
│  - applyToText(string $text, bool $includeHighRisk=false)           │
└─────────────────────────────────────────────────────────────────────┘
```

## Componente 1: UI de effectiveness en tab Aprobadas

### Cambios en Alpine

Estado nuevo:
```js
approvedSortBy: 'applies_count',          // 'applies_count' | 'id' | 'approved_at'
approvedSortDir: 'desc',                  // 'asc' | 'desc'
approvedFilter: { inactive: false, minInactiveDays: 30 },
approvedAudit: null,                      // stats del último audit (lazy load)
```

Sort computado: `approvedSorted` reemplaza el ordenamiento directo en `approvedFiltered`. Se ordena en JS (no en backend) porque ya tenemos toda la lista en memoria (la carga AJAX devuelve TODAS las aprobadas).

Filtro inactivas: se aplica post-sort. La columna "Aplicaciones" muestra además un badge de color (🟢/🟡/🔴) implementado como dot de 8px con `bg-emerald-500`, `bg-amber-500`, `bg-rose-500`.

Botón bulk cleanup: cuando el filtro "inactivas" está activo, aparece un botón naranja "Eliminar N inactivas" que abre modal de confirmación y llama al endpoint nuevo.

### Cambios en HTML

- Header de columna "Aplicaciones" se vuelve clickeable (`<th @click="toggleSort('applies_count')">` con ícono de flecha según dirección).
- Debajo del filtro existente, agregar una sección "Estado de uso" con checkboxes:
  - `[ ] Solo inactivas (applies_count = 0)`
  - `[ ] Creadas hace más de [30] días`
- El panel de bulk action bar se extiende: si `approvedFilter.inactive` está activo, muestra "Eliminar N inactivas" además de "Excluir N" y "Eliminar N".

## Componente 2: Bulk destroy inactive

### Endpoint

`POST /ia/correcciones/bulk-destroy-inactive`

Body:
```json
{
  "min_age_days": 30,
  "max_count": 500
}
```

Validación: `min_age_days` integer ≥ 0, default 30. `max_count` integer ≥ 1, default 500, max 5000.

Lógica:
```php
public function bulkDestroyInactive(Request $request, CorrectionService $service): JsonResponse
{
    $validated = $request->validate([
        'min_age_days' => 'nullable|integer|min:0|max:3650',
        'max_count' => 'nullable|integer|min:1|max:5000',
    ]);
    $minAgeDays = $validated['min_age_days'] ?? 30;
    $maxCount = $validated['max_count'] ?? 500;

    $admin = $this->adminUser();

    // Pre-flight: contar y cap-ear antes de tocar nada
    $candidates = Correction::approved()
        ->where('applies_count', 0)
        ->where('created_at', '<=', now()->subDays($minAgeDays))
        ->orderBy('id')
        ->limit($maxCount)
        ->pluck('id')
        ->all();

    if (empty($candidates)) {
        return response()->json([
            'destroyed' => 0,
            'message' => 'No hay inactivas que cumplan los criterios.',
            'bulk_action_id' => null,
        ]);
    }

    $result = $service->bulkDestroy($candidates, $admin);
    // ↑ ya devuelve destroyed, bulk_action_id, undo_expires_at

    return response()->json($result);
}
```

### Reuso de infraestructura existente

`CorrectionService::bulkDestroy()` ya:
- Crea fila en `correction_bulk_actions` con `action='bulk_destroy'`.
- Crea filas en `correction_bulk_action_items` con `correction_id`.
- Hace `DELETE` real sobre `corrections` (hard delete).
- Devuelve `{ destroyed, errors, bulk_action_id, undo_expires_at }`.

`bulk-destroy` no es reversible (la action es `bulk_destroy`), pero queda auditado en `correction_bulk_actions` con referencia a los IDs borrados, suficiente para reconstruir manualmente si el admin necesita.

## Componente 3: Atomicity suggestions

### Endpoint

`GET /ia/correcciones/{id}/atomicity-suggestions`

Response:
```json
{
  "unigrams": [
    { "wrong": "touristic", "suggested_correct": "turístico", "occurrences_in_corrections": 7, "confidence": "high" },
    { "wrong": "attractives", "suggested_correct": "atractivos", "occurrences_in_corrections": 4, "confidence": "high" },
    { "wrong": "people", "suggested_correct": null, "occurrences_in_corrections": 1, "confidence": "low" }
  ],
  "bigrams": [
    { "wrong": "touristic attractives", "suggested_correct": "atractivos turísticos", "occurrences_in_corrections": 3, "confidence": "high" }
  ],
  "already_in_dict_unigrams": ["the", "and", "of"],
  "already_in_dict_bigrams": ["in the world"]
}
```

### Algoritmo

1. Tokenizar `wrong_text` en lowercase, split `[\s\p{P}]+`, filtrar tokens < 3 chars y stopwords (lista hardcodeada en el service: `the, and, of, to, a, in, is, it, that, for, on, with, as, at, by, from, be, are, was, were, an, or, but, if, then, so, this, these, those, i, you, he, she, we, they, my, your, our, their`).

2. **Unigramas**:
   - Para cada token único no en stopwords, buscar en el diccionario aprobado: ¿existe ya `corrections.wrong_normalized = token`? Si sí → `already_in_dict_unigrams`. Si no → candidato.
   - Para el candidato, buscar en TODAS las correcciones aprobadas (`wrong_text LIKE '%token%'`) y extraer el `correct_text` correspondiente. Si ≥80% de las veces el token se traduce a la misma cadena → `suggested_correct = <esa cadena>`, `confidence = high`. Si <80% → `suggested_correct = null`, `confidence = low`.

3. **Bigramas** (pares consecutivos de tokens no stopwords):
   - Misma lógica: si no existe en diccionario, buscar en aprobadas y proponer traducción mayoritaria.

4. Implementación en SQL:
   ```php
   $occurrences = Correction::approved()
       ->where('wrong_text', 'LIKE', '%' . $token . '%')
       ->select('correct_text', DB::raw('COUNT(*) as cnt'))
       ->groupBy('correct_text')
       ->orderByDesc('cnt')
       ->limit(5)
       ->get();
   ```
   Luego parsear el `correct_text` para extraer el segmento que corresponde al token (heurística: si el token aparece N veces en `wrong_text`, el segmento N-ésimo del `correct_text` es su traducción probable — no perfecto, pero es lo que usa el LLM para casos similares).

5. **POST /correcciones/{id}/atomicity-suggestions/bulk-add** con body `{items: [{wrong, correct}, ...]}`: crea correcciones nuevas (status=approved, source='atomicity-from-{sourceCorrectionId}') usando `CorrectionService::upsertApproved()`.

   Validación: cada `wrong` < 100 chars (garantiza que son tokens/bigramas), `correct` requerido.

   El admin marca los checkboxes, edita el `correct` inline si quiere, y hace click en "Agregar N como nuevas correcciones".

### Cap de generación

`unigrams + bigrams` máximo 20 candidatos por corrección (los más frecuentes). Si la corrección tiene 25 tokens, se devuelven los 20 con mayor `occurrences_in_corrections`.

## Componente 4: Re-prompting atómico

### Cambios a `LlmCorrectionSuggester::getSystemPrompt()`

Diff (lo importante):
```diff
+ REGLA DE ATOMICIDAD (NUEVO):
+ - PREFERÍ siempre la versión más corta. Si una palabra suelta es traducible
+   de forma estable (aparece ≥3 veces en el corpus), proponé ESA, no la frase.
+ - SOLO proponé una frase de >4 palabras si aparece ≥8 veces textuales en el corpus
+   Y sus palabras constituyentes NO son traducibles individualmente.
+ - Para cada frase larga que sí propongas, listá también en `atomic_candidates`
+   los bigramas/trigramas que la componen (campo nuevo del JSON).
+ - Penalizá frases con >8 palabras salvo que frecuencia ≥15.
+ - Penalizá frases con >12 palabras siempre (no las propongas).
```

### Cambios a `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()`

```php
public function looksLikeBrandOrProperNoun(string $wrong, ?int $freq = null): bool
{
    // ... reglas existentes (siglas, mayúsculas internas, marcas) ...

    // NUEVO: filtro de longitud atómica
    $wordCount = str_word_count($wrong, 0, 'áéíóúñüÁÉÍÓÚÑÜ');
    if ($wordCount > 12) return true;        // siempre fuera
    if ($wordCount > 6 && ($freq ?? 0) < 8) return true;  // muy larga y rara
    if ($wordCount > 8) return true;         // demasiado larga sin excepción

    return false;
}
```

Pero el método retorna `bool` (true = descartar). Necesitamos que el caller sepa la RAZÓN del descarte. Cambio:
- Renombrar a `evaluateCandidate(string $wrong, ?int $freq): array` que retorna `['accept' => bool, 'reason' => ?string]`.
- Caller cuenta `rejected_by_filter` (marca/sigla), `rejected_by_length` (longitud), `accepted`.

### Campo nuevo en output del LLM

El prompt pide `atomic_candidates` (array de `{wrong, correct}`) para cada candidato principal. Esto requiere actualizar el parsing del JSON en `LlmCorrectionSuggester::suggest()`.

Storage: los `atomic_candidates` se insertan como correcciones adicionales con `source='ai-suggest-YYYY-MM-DD-atomic-from-{parent_id}'` y se linkean vía campo `source_segment_id` o nuevo campo `parent_correction_id`. Por simplicidad, los metemos como `source` custom; el admin los filtra por origen igual que cualquier otro.

### Métricas de reporting

`php artisan corrections:ai-suggest` ahora imprime al final:
```
Mined:                42
Inserted:             35
Skipped (duplicate):  5
Rejected by filter:   2  (marcas/siglas)
Rejected by length:   1  (frases demasiado largas)
Promoted to atomic:   8  (bigramas extraídos de frases largas)
```

## Componente 5: Audit command

`php artisan corrections:dictionary-audit` (alias `corrections:audit`):

Output (text/markdown):
```
## Dictionary Audit Report — 2026-08-02 14:56:21

### Totals
- Approved: 2,678 (active)
- Pending: 12
- Rejected: 31
- Total: 2,721

### Effectiveness distribution
- 0 applications:  2,106 (78.6%)   ← inactivas
- 1-5:               351 (13.1%)
- 6-20:               85 (3.2%)
- 21-100:             61 (2.3%)
- 100+:               75 (2.8%)   ← killer rules

### Top 20 unigrams inside wrong_texts
the    1992   and    865   that  495   you  358   this 288   ...

### Top 20 bigrams inside wrong_texts
and the        134    with the     61    for the   51   ...

### Top 20 trigrams inside wrong_texts
one of the       26    part of the  13    what is the 10   ...

### Clusters (Jaccard ≥ 60% with ≥3 others)
Total: 0 — phrases are not clustering into reusable lemmas.

### Duplicates / conflicts
Exact duplicates (same wrong+correct): 0
Conflicts (same wrong → different correct): 0
```

Implementación: encapsula toda la lógica en `App\Services\Ia\DictionaryAudit::run(): array` (retorna array estructurado) y un command thin wrapper que formatea como tabla.

## Edge cases y mitigaciones

| Caso | Mitigación |
|---|---|
| Admin borra inactivas que después hubieran matcheado una transcripción nueva | Ventana de undo disponible (`corrections.undo_window_minutes`). Más allá, se asume aceptable porque eran inactivas. |
| Atomicity suggestion propone `correct` incorrecto para un bigrama | `confidence=low` cuando no hay ≥80% consenso. Admin edita inline antes de aceptar. |
| Tokenizer mete tokens con caracteres Unicode raros | Usar `preg_split('/[\s\p{P}]+/u', ...)` con flag `u`. |
| Re-prompt rompe el AI suggest existente | Cambio conservador: agrega regla, no reemplaza. Dry-run antes/después para validar. |
| Audit tarda mucho con 100k+ correcciones | Query pre-agregada con `selectRaw` + cache 5 min (key `dictionary_audit:{YYYY-MM-DD-HH}`). |
| Bulk destroy inactive afecta correcciones que están referenciadas por `correction_bulk_action_items` | `correction_bulk_actions` guarda `correction_id` hardcoded; los items huérfanos no rompen nada (la FK ya es laxa). |
| Migración de `risk_level` se ejecuta antes del backfill | El deploy corre `migrate` + `corrections:context-audit --apply` en orden, ambos comandos son idempotentes. Si la migración corre sola, las reglas nuevas se aprueban con `risk='low'` y luego el auditor las marca. |
| `applyToText()` cambia firma (nuevo parámetro `includeHighRisk`) | Parámetro default `false`, no rompe callers existentes. Verificamos con grep antes de mergear. |
| Override manual de `risk='low'` se pierde al re-correr auditor | El auditor respeta `risk_level != 'low'` y skipea; un override manual a 'low' SÍ se sobrescribirá, pero ese es el comportamiento esperado (el admin puede re-aplicar el auditor si quiere). |

## Componente 6: Context-shift protection (risk_level)

### Migración

```php
Schema::table('corrections', function (Blueprint $table) {
    $table->enum('risk_level', ['low', 'medium', 'high'])
        ->default('low')
        ->after('source_segment_id');
    $table->index('risk_level');
});
```

Backfill inmediato en el mismo deploy:

```bash
php artisan migrate
php artisan corrections:context-audit --apply
```

El segundo comando itera todas las `approved` y setea `risk_level` según la blocklist (sin pisar valores que no sean 'low' default).

### Blocklist estática

`app/config/corrections.php`:

```php
return [
    // ... existing keys ...

    'context_sensitive' => [
        'filler_words' => [
            ['term' => 'like', 'risk' => 'high', 'note' => 'muletilla cuando no es comparación'],
            ['term' => 'you know', 'risk' => 'high', 'note' => 'muletilla'],
            ['term' => 'i mean', 'risk' => 'high', 'note' => 'muletilla'],
            ['term' => 'basically', 'risk' => 'medium', 'note' => 'puede ser contenido o muletilla'],
            ['term' => 'literally', 'risk' => 'medium', 'note' => 'muletilla intensificadora'],
            ['term' => 'honestly', 'risk' => 'medium', 'note' => 'muletilla o adverbio'],
            ['term' => 'obviously', 'risk' => 'medium', 'note' => 'muletilla o adverbio'],
            ['term' => 'sort of', 'risk' => 'high', 'note' => 'muletilla dubitativa'],
            ['term' => 'kind of', 'risk' => 'high', 'note' => 'muletilla dubitativa'],
            ['term' => 'right', 'risk' => 'medium', 'note' => 'tag question'],
            ['term' => 'okay', 'risk' => 'medium', 'note' => 'muletilla o respuesta'],
        ],
        'false_friends' => [
            ['term' => 'actually', 'safe_translations' => ['en realidad', 'de hecho', 'la verdad'], 'unsafe' => ['actualmente'], 'risk' => 'high'],
            ['term' => 'eventually', 'safe_translations' => ['con el tiempo', 'al final'], 'unsafe' => ['finalmente'], 'risk' => 'high'],
            ['term' => 'sensitive', 'safe_translations' => ['sensible', 'susceptible'], 'risk' => 'medium'],
            ['term' => 'sympathetic', 'safe_translations' => ['comprensivo', 'empático'], 'unsafe' => ['simpático'], 'risk' => 'high'],
            ['term' => 'actual', 'safe_translations' => ['real', 'verdadero'], 'unsafe' => ['actual'], 'risk' => 'high'],
            ['term' => 'realize', 'safe_translations' => ['darse cuenta'], 'risk' => 'low'],
            ['term' => 'eventual', 'safe_translations' => ['final', 'posterior'], 'risk' => 'medium'],
        ],
    ],
];
```

Las listas son extensibles sin redeploy: cualquier cambio a `config/corrections.php` toma efecto en el próximo request (la config no se cachea agresivamente en este proyecto, validado por uso existente en otros lugares).

### ContextShiftAuditor

`app/app/Services/Ia/ContextShiftAuditor.php`:

```php
class ContextShiftAuditor
{
    public function audit(int $limit = 0): array
    {
        $config = config('corrections.context_sensitive');
        $results = [];

        Correction::approved()
            ->when($limit > 0, fn($q) => $q->limit($limit))
            ->select(['id', 'wrong_text', 'correct_text', 'risk_level'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$results, $config) {
                foreach ($rows as $r) {
                    $result = $this->evaluateOne($r, $config);
                    if ($result !== null) {
                        $results[$r->id] = $result;
                    }
                }
            });

        return $results;
    }

    public function evaluateOne(Correction $r, array $config): ?array
    {
        $wrong = mb_strtolower($r->wrong_text);
        $correct = mb_strtolower($r->correct_text);

        // Check false friends first (más específico)
        foreach ($config['false_friends'] as $ff) {
            if (preg_match('/\b' . preg_quote($ff['term'], '/') . '\b/i', $wrong)) {
                $unsafeHit = null;
                foreach ($ff['unsafe'] ?? [] as $u) {
                    if (mb_strpos($correct, $u) !== false) {
                        $unsafeHit = $u;
                        break;
                    }
                }
                if ($unsafeHit !== null) {
                    return [
                        'risk' => 'high',
                        'reason' => "false friend: '{$ff['term']}' translated as '$unsafeHit' (unsafe); safe: " . implode(', ', $ff['safe_translations'] ?? []),
                    ];
                }
            }
        }

        // Check filler words
        foreach ($config['filler_words'] as $f) {
            if (preg_match('/\b' . preg_quote($f['term'], '/') . '\b/i', $wrong)) {
                return [
                    'risk' => $f['risk'],
                    'reason' => "contains '{$f['term']}' ({$f['note']})",
                ];
            }
        }

        return null; // sin issue detectado
    }

    public function applyToDb(bool $dryRun = true): array
    {
        $audit = $this->audit();
        $updated = 0;
        $skipped = 0;

        foreach ($audit as $id => $suggestion) {
            $correction = Correction::find($id);
            if (!$correction) continue;
            // No pisar overrides manuales (risk != 'low')
            if ($correction->risk_level !== 'low') {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $correction->risk_level = $suggestion['risk'];
                $correction->save();
            }
            $updated++;
        }

        return ['updated' => $updated, 'skipped_manual' => $skipped, 'dry_run' => $dryRun];
    }
}
```

### `Correction::applyToText()` modificado

```php
public static function applyToText(string $text, bool $includeHighRisk = false): string
{
    $query = static::approved()
        ->orderByRaw('LENGTH(wrong_normalized) DESC');

    if (!$includeHighRisk) {
        $query->where('risk_level', '!=', 'high');
    }

    $corrections = $query->get(['wrong_normalized', 'correct_text']);

    if ($corrections->isEmpty()) {
        return $text;
    }

    $result = $text;
    foreach ($corrections as $correction) {
        if ($correction->wrong_normalized === '') continue;
        $pattern = '/\b' . preg_quote($correction->wrong_normalized, '/') . '\b/i';
        $result = preg_replace($pattern, $correction->correct_text, $result);
    }
    return $result;
}
```

**Impacto en otros callers** (búsqueda con grep antes de mergear):
- `app/app/Services/Ia/CorrectionService.php` — `applyRetroactive()` debe pasar `false` explícito (defensivo, no rompe nada).
- `app/app/Console/Commands/ApplyCorrectionsCommand.php` — agregar flag `--include-high-risk` (default false), pasar al método retroactivo.
- Cualquier llamada directa en código de aplicación (transcripción en vivo) sigue usando default = safe.

### Pre-approval safeguard en `approve()` y `store()`

```php
public function store(Request $request, CorrectionService $service)
{
    $request->validate([
        'wrong' => 'required|string|max:500',
        'correct' => 'required|string|max:500',
    ]);

    $admin = $this->adminUser();
    $correction = $service->upsertApproved($request->wrong, $request->correct, $admin);

    $response = ['correction' => $correction->load('proposedBy', 'approvedBy')];

    // Context warning
    $auditor = app(ContextShiftAuditor::class);
    $dummy = new Correction(['wrong_text' => $request->wrong, 'correct_text' => $request->correct, 'risk_level' => 'low']);
    $warning = $auditor->evaluateOne($dummy, config('corrections.context_sensitive'));
    if ($warning !== null) {
        $response['context_warning'] = $warning + ['matched' => $request->wrong];
    }

    return response()->json($response, 201);
}
```

El frontend captura `context_warning` y muestra un modal antes de confirmar el alta.

### UI: tab "Contexto sensible"

- Lista correcciones con `risk_level IN ('medium', 'high')`.
- Filtros: por risk, por término (input search), por origen.
- Cada fila: badge de risk, razón, acciones:
  - **Cambiar a low** (override manual, persiste con `risk_level='low'`).
  - **Editar traducción** (corrige in-place; útil para false friends donde queremos cambiar `actualmente` por `en realidad`).
  - **Mantener** (no-op).
  - **Eliminar** (usa `bulk-destroy`).
- Botón "Aplicar sugerencias del auditor" ejecuta `ContextShiftAuditor::applyToDb()` en batch.

### Migración de datos (backfill)

Comando:

```bash
php artisan corrections:context-audit
```

Salida esperada en producción actual (basado en el audit manual que hicimos):

```
Dry-run: 75 correcciones marcadas como risk=high o medium
Para aplicar: php artisan corrections:context-audit --apply
```

Detalle de las 75 reglas a marcar (sample de las 10 más relevantes):
- id=1468 `you know → sabes` → high (muletilla)
- id=1362 `Paines like Colombia...` → high (contiene `like`)
- id=2407 `...rapero, como una trama...` → high (contiene `like` como comparación OK pero también `like` como muletilla en otras partes; el auditor lo marca porque no puede desambiguar)
- ... (las 75 detectadas por el script PHP que corrimos hoy)

## Decisiones rechazadas (y por qué)

- **Auto-eliminar inactivas vía cron**: demasiado agresivo. El admin puede revisar con `dictionary-audit` y decidir.
- **Stemming/lemmatization automático**: requiere NLP libs o un servicio extra. El valor marginal no compensa para atomicidad literal.
- **Tabla nueva `correction_metrics`**: ya tenemos `applies_count` en la tabla principal. Suficiente.
- **Migrar todas las correcciones a su versión atómica automáticamente**: peligroso. Requiere confiar en la traducción; el admin debe aprobar cada extracción.
- **POS-tagging para desambiguar `like`/`you know`**: requiere NLP libs o LLM por cada match. Caro y lento. La solución pragmática es `risk_level='high'` + opt-in manual.
- **Eliminar todas las correcciones con `you know` / `like` automáticamente**: el admin puede que quiera mantener algunas donde SÍ aplica la traducción (ej: "like an angel" como comparación real). Por eso el workflow es: marcar risk=high + revisión manual caso por caso.
