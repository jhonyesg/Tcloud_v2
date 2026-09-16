## Context

See `proposal.md` for motivation. Current state that's relevant to the design:

- **`TranscriptionCoherencePass::apply()`** (`app/app/Services/Ia/TranscriptionCoherencePass.php:69`) declares `array{index, text}` but the extractor at line 137 reads `$segments[$idx]['id'] ?? null` — always null because the caller (`TranscriptionProcessor::persistSegmentsAndUpdate`) only passes `['index', 'text', 'score']`. This is bug #1.
- **`learnFromCorrections()`** (line 158) assigns `wrong=$before` and `correct=$newText` (full segments), relying on a `>4 palabras` filter in `proposeLearned()` to gate it. Empirically, `str_word_count` of strings like `"Just think I grumble."` returns 4 (passes the gate) even though the rule is a near-full-sentence translation. This is bug #2.
- **6.086 pending** in `corrections` table (as of 2026-08-18 audit), **6.035 from `ai-coherence-learn`**, only 32 with `source_segment_id` populated, 0 matches with the source segment's `text_raw`/`text`. The remaining 64 pending come from `ai-suggest-*`/`auto-cycle-*` and are correct-shape (correct_text appears in segments), are not part of this bug.
- **The bulk-moderation machinery is already battle-tested**: `correction_bulk_actions` + `correction_bulk_action_items` tables, `CorrectionService::bulkApprove()` and `undoBulkAction()`, 5-minute undo window via `corrections_undo_window_minutes` config, `/correcciones/undo/{bulkActionId}` route (already in `app/routes/web.php:255-257`). The triage can reuse it without new persistence.
- **The apply-retroactive machinery for tracking long-running runs is also battle-tested**: `corrections_apply:{runId}` cache key + `corrections_apply:active` pointer + orphan cleanup + polling endpoint. The triage can reuse the exact same pattern under `corrections_triage:*` namespace.
- **No permission to modify existing schemas**: no migrations. Triage reads/writes existing tables only.

## Goals / Non-Goals

**Goals:**
- Fix the extractor so future `ai-coherence-learn` corrections are auditable (segment linked, phrases bounded).
- Reduce the 6.086 pending to a small, context-rich subset that the admin can review in a single sitting, with a clear per-layer report.
- Optional auto-approve path limited to classifier-`KEEP` rules (orthographic variants), with the existing 5-minute undo window as the safety net.
- Reuse existing bulk-moderation machinery (no new tables, no migration).

**Non-Goals:**
- No migration of the `corrections` schema (the existing `source_segment_id` column is enough).
- No change to the IA coherence model, threshold, or batch size settings — fix is purely in the post-processing of pairs.
- No retroactive pass that runs `applyToSegments` over historical `text` of segments — that's `applyRetroactive` and the admin can trigger it manually after triage.
- No auto-approve of `QUARANTINE` or `NOISE` rules — those are *explicitly* what we don't want to repeat.
- No new modal infrastructure: copy the existing `applyRetroactive` modal pattern.

## Decisions

### Decision 1: Diff extraction strategy for `learnFromCorrections`

**Choice**: Use a **common-prefix / common-suffix trim** to find the minimal changed substring, then split on sentence/clause boundaries and emit each clause-level diff as a separate pair. If any clause is >4 words, the clause is dropped.

```php
// Pseudocódigo en TranscriptionCoherencePass::learnFromCorrections()
[$prefixLen, $suffixLen] = commonPrefixSuffixLen($before, $newText);
$changedBefore = mb_substr($before, $prefixLen, mb_strlen($before) - $prefixLen - $suffixLen);
$changedAfter  = mb_substr($newText,  $prefixLen, mb_strlen($newText)  - $prefixLen - $suffixLen);

// Split en cláusulas (.,;:) y emitir pares por cláusula.
$beforeClauses = preg_split('/(?<=[.;:])\s+/u', $changedBefore);
$afterClauses  = preg_split('/(?<=[.;:])\s+/u', $changedAfter);
// Emparejar cláusulas 1-a-1 (longitudes similares), descartar cláusulas >4 palabras.
```

**Rationale**: 
- Common-prefix/suffix trim es robusto sin NLP: para `the cooperativas están dotadas of two motors` → `las cooperativas están dotadas de dos motores`, queda `the` ↔ `las` + `of` ↔ `de` + `two` ↔ `dos` (tres pares limpios de 1 palabra).
- No requiere el LLM (gratis, determinístico).
- Es el algoritmo clásico de Git, de Myers, simplificado para prosa corta.

**Alternatives considered**:
- **Diff Myers (algoritmo)**: más preciso pero overkill para segmentos de 1-3 frases; añade dependencia.
- **Diff embebido PHP `diff()`**: solo strings crudos, no respeta tokens Unicode.
- **Diff por LLM**: costoso y no determinístico — y ya tenemos a la IA corrigiendo, duplicamos trabajo.
- **Mantener segmento entero y solo endurecer el filtro >4 palabras**: hoy se evade con `str_word_count` mal contado; el parche solo en el filtro deja el problema latente.

### Decision 2: Service class vs single-file command

**Choice**: Split into `CorrectionTriageService` (orchestrator + cache state) + `CorrectionsTriagePendingCommand` (CLI wrapper that delegates). The service is invoked by the controller the same way `CorrectionService::applyRetroactive()` works.

**Rationale**: Matches the existing pattern in this codebase: `CorrectionsApplyRunCommand` + `CorrectionService::applyToSegments()` + `CorreccionesController::applyRetroactive()`. Consistency lowers cognitive load for future maintainers.

**Alternatives considered**:
- All-in-one in the command (skip the service): makes the controller call `$this->call('corrections:triage-pending', ...)` awkward; can't share state with the polling endpoint cleanly.
- Use Laravel Queue instead: triage is bounded (<10k rows, <10s per layer), no need for queue infra; cache polling is already the simpler pattern used here.

### Decision 3: Cache namespace for triage runs

**Choice**: Reuse the `corrections_apply:*` cache keys and `corrections_apply:active` pointer pattern verbatim, but with `corrections_triage:*` prefix. Same orphan-cleanup logic.

**Rationale**: proven pattern, no new code paths for cleanup. Two namespaces prevents a triage from blocking an applyRetroactive (and vice versa) under contention.

### Decision 4: Reuse `CorrectionBulkAction` instead of creating a new mechanism

**Choice**: Auto-approve of `KEEP` rules uses `CorrectionService::bulkApprove([ids], $admin)` which already creates `CorrectionBulkAction(action='bulk_approve')` + per-item snapshots. No new tables.

**Rationale**: The 5-minute undo, the bulk_action_id, the UI toast pattern, the "already undone" semantics, and the test fixtures are all in place. Inventing a new undo mechanism is unnecessary complexity.

### Decision 5: Bulk max via `--max` chunking, not queue

**Choice**: The triage command processes up to `--max` candidates (default 10.000) in a single process loop, with `sleep(0)` between layers (no DB transactions, just streaming reads). For the current 6.086, a single run completes in <30s.

**Rationale**: Postgres handles 6k SELECT+UPDATE loops trivially. Queue would add 30-60s overhead per batch and complicate the polling UX.

### Decision 6: Source segment id propagation via post-INSERT hydration

**Choice**: Add a public method `hydrateCoherenceLearnedSourceSegments(int $transcriptionId)` on `TranscriptionCoherencePass` that runs a single UPDATE JOIN after segments have been INSERTed. The caller `TranscriptionProcessor::persistSegmentsAndUpdate()` calls it inside the same DB transaction, right after `TranscriptionSegment::insert($chunk)`.

```sql
UPDATE corrections c
SET source_segment_id = ts.id
FROM transcription_segments ts
WHERE c.source = 'ai-coherence-learn'
  AND c.source_segment_id IS NULL
  AND c.status = 'pending'
  AND c.created_at > now() - interval '5 minutes'
  AND ts.transcription_id = ?
  AND position(c.wrong_text in ts.text_raw) > 0
```

**Rationale**: At `apply()` time the segments don't have DB ids yet — they're created in the very next INSERT after apply() returns. Trying to populate source_segment_id inside apply() is structurally impossible (the row doesn't exist). The post-INSERT hydration is a single SQL statement scoped to one transcription, runs in milliseconds, and correctly links each just-learned correction with its origin segment using `position()` for safe substring matching.

The PHPDoc of `apply()` still declares `array{index, text}` (we keep the same input shape; no breaking change). The `wrong_text` is short (≤4 words from the diff-by-clause strategy), so `position()` matches are reliable.

**Alternative considered**: extending `apply()` to receive a pre-allocated `id` (decision-as-originally-drafted) — rejected because it's structurally impossible given the data flow.

## Risks / Trade-offs

- **[Bug in old data still exists]** → 6.035 existing `ai-coherence-learn` corrections already have wrong/large `wrong_text`. **Mitigation**: the Capa 1 (longitud >4 palabras) of the triage command deletes them as one of its first layers. After triage, no flawed rules remain.

- **[Auto-approve of `KEEP` could surprise the admin if classifier is overly aggressive]** → **Mitigation**: undo de 5 min con toast visible + reporte por capa que muestra exactamente cuáles se auto-aprobaron. Si el admin ve "KEEP" aprobando algo que él considera ruido, deshace en bloque.

- **[Triage runs over a 6k-row table 6 times sequentially]** → Capa 1 (longitud) y Capa 2 (source_segment_id NULL) y Capa 3 (duplicado vs approved) se pueden combinar en una sola query SQL con un `WHERE status='pending' AND (... OR ... OR ...)`. Capa 4 (brand) y Capa 5 (classifier) sí requieren PHP por el lado del `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` y `EnEsRuleClassifier::classify()`. Estimado total: <30s para 6k reglas en hardware actual.

- **`str_word_count` vs `preg_split` para el conteo de palabras en español]** → El extractor nuevo usa `preg_split('/\s+/u', strip_punctuation($text))` en lugar de `str_word_count`. Es lo que el spec requiere. Mismas funciones se usan en el triage de Capa 1 (paridad entre extractor y triage).

- **[Capa 6 (warm context) puede tardar minutos]** → Se ejecuta solo sobre las reglas que sobreviven las 5 capas anteriores. Si sobreviven ~50, son 50 lookups a `transcription_segments` (~0.2s cada uno vía índice) = ~10s. Aceptable dentro del modal.

- **[Cron semanal agrega otra entrada al schedule]** → Riesgo bajo. La entrada es `--dry-run` por default (solo reporte); el admin decide si aplicar. Colocada después del `corrections:cleanup-undo-log` existente (04:00) → propuesta: sábado 04:30.

## Migration Plan

No DB migration. Deploy sequence:

1. Merge con los cambios de código.
2. Verificar manualmente con: `php artisan corrections:triage-pending --dry-run --days=14` que devuelve un reporte razonable.
3. Si el reporte es coherente, lanzar desde UI con `auto_approve_keep=true`. Confirmar que el toast de undo aparece.
4. Después de 5 min (o cuando el admin decida que se ve bien), correr `CorreccionesController::applyRetroactive(dry_run=true)` para previsualizar el impacto, luego con `dry_run=false` para aplicar.
5. Verificar logs `storage/logs/corrections-triage.log` para cualquier warning.

**Rollback**: 
- Si el extractor daña producción post-deploy: revertir el commit del `TranscriptionCoherencePass.php`. Las reglas ya emitidas con bugs (las 6.035) siguen en pending y se pueden descartar en bloque desde `/ia/correcciones/bulk-destroy-pending`.
- Si el auto-approve de triage mete basura: `POST /ia/correcciones/undo/{bulkActionId}` dentro de 5 min. Sin él, el admin puede `DELETE /correcciones/{id}` por fila.

## Open Questions

- ¿Top de candidatas por corrida: 10.000 es suficiente para producción o necesitamos 50.000? → Default 10.000 configurable; resolver con `--max` según necesidad operativa.
- ¿El botón "Triage pendientes" se renderiza también para el admin con rol `editor` o solo `admin`? → Solo `admin` (mismo middleware que el resto del bloque `/ia`). Resolver en controller, no en diseño.
