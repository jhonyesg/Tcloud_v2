# Tasks: Dictionary atomicity — pruning, effectiveness UI y prompts atómicos

## 1. Service: DictionaryAudit (read-only)

- [ ] Crear `app/app/Services/Ia/DictionaryAudit.php`:
  - `run(): array` retorna estructura con `totals`, `effectiveness_distribution`, `top_unigrams`, `top_bigrams`, `top_trigrams`, `clusters`, `duplicates`.
  - `topUnigrams(int $limit = 30): array` — cuenta tokens dentro de `wrong_text` approved.
  - `topBigrams(int $limit = 30): array` y `topTrigrams(int $limit = 30): array`.
  - `effectivenessDistribution(): array` — buckets 0 / 1-5 / 6-20 / 21-100 / 100+.
  - `duplicatesAndConflicts(): array` — {exact_dups: int, conflicts: int}.
  - `clusters(float $jaccardThreshold = 0.6, int $minOverlaps = 3): array`.
- [ ] `php -l` validar syntax.

## 2. Service: extractAtomicitySuggestions

- [ ] En `app/app/Services/Ia/CorrectionService.php`, agregar método `extractAtomicitySuggestions(Correction $c, int $maxResults = 20): array`:
  - Tokeniza `wrong_text` con `preg_split('/[\s\p{P}]+/u', mb_strtolower($c->wrong_text), -1, PREG_SPLIT_NO_EMPTY)`.
  - Filtra stopwords (lista hardcodeada en el service) y tokens < 3 chars.
  - Genera unigramas (tokens únicos no stopword) y bigramas (pares consecutivos no stopword).
  - Para cada candidato, busca en `corrections` (approved) por `wrong_text LIKE '%token%'` y agrupa por `correct_text`. Calcula ratio de consenso. Si ≥80% → `confidence='high'` y `suggested_correct = top1`. Si <80% → `confidence='low'` y `suggested_correct = null`.
  - Dedupea contra el diccionario existente (`wrong_normalized IN (SELECT wrong_normalized FROM corrections WHERE status='approved')`).
  - Cap a `maxResults` candidatos totales ordenados por `occurrences_in_corrections DESC`.
- [ ] `php -l` validar.

## 3. Controller: atomicity-suggestions endpoints

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `+atomicitySuggestions(int $id, CorrectionService $service): JsonResponse`.
  - `+bulkCreateAtomicityFromCorrection(Request $request, int $id, CorrectionService $service): JsonResponse` (valida items[], crea correcciones nuevas con source='atomicity-from-{parentId}').
- [ ] En `app/routes/web.php`, agregar:
  - `Route::get('/correcciones/{id}/atomicity-suggestions', ...)` (whereNumber).
  - `Route::post('/correcciones/{id}/atomicity-suggestions/bulk-add', ...)`.

## 4. Service: bulkDestroyInactive

- [ ] En `app/app/Services/Ia/CorrectionService.php`:
  - `+bulkDestroyInactive(int $minAgeDays, int $maxCount, User $by): array`:
    - Query `Correction::approved()->where('applies_count', 0)->where('created_at', '<=', now()->subDays($minAgeDays))->orderBy('id')->limit($maxCount)->pluck('id')`.
    - Si vacío → return `['destroyed' => 0, 'bulk_action_id' => null, 'message' => 'No hay candidatas.']`.
    - Else, llama a `$this->bulkDestroy($ids, $by)` y devuelve su resultado tal cual.
- [ ] `php -l` validar.

## 5. Controller: bulk-destroy-inactive

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `+bulkDestroyInactive(Request $request, CorrectionService $service): JsonResponse`:
    - Valida `min_age_days` (int, min 0, max 3650, default 30), `max_count` (int, min 1, max 5000, default 500).
    - Llama al service. Retorna JSON.
- [ ] En `app/routes/web.php`:
  - `Route::post('/correcciones/bulk-destroy-inactive', ...)`.

## 6. Controller + ruta: audit endpoint

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `+auditReport(DictionaryAudit $audit): JsonResponse` retorna JSON con cache 5min (`Cache::remember('dictionary_audit:'.now()->format('YmdHi'), 300, fn() => $audit->run())`).
- [ ] En `app/routes/web.php`:
  - `Route::get('/correcciones/dictionary-audit', ...)`.

## 7. CLI: dictionary-audit command

- [ ] Crear `app/app/Console/Commands/DictionaryAuditCommand.php`:
  - Signature: `corrections:dictionary-audit` (alias `corrections:audit`).
  - Llama a `DictionaryAudit::run()`.
  - Imprime secciones en formato tabla con `$this->table()`.
- [ ] `php -l` validar.
- [ ] Verificar con `php artisan list` que el comando se registra.

## 8. UI: effectiveness metrics en tab Aprobadas

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Alpine state nuevo: `approvedSortBy`, `approvedSortDir`, `approvedFilter: { inactive: false, minInactiveDays: 30 }`, `approvedAudit: null`.
  - Computed `approvedSorted`: ordena `approvedFiltered` por `approvedSortBy`/`approvedSortDir`.
  - Header de columna "Aplicaciones" clickeable con flecha indicadora de dirección.
  - Badge de color (🟢/🟡/🔴) en cada fila según `applies_count`.
  - Sección de filtros nueva debajo de los existentes:
    - Checkbox "Solo inactivas (applies_count = 0)"
    - Input numérico "Creadas hace más de [N] días"
    - Botón "Eliminar N inactivas" (visible solo cuando `approvedFilter.inactive` está activo y hay selección).
  - Modal de confirmación estilo "Vas a eliminar X correcciones aprobadas con 0 aplicaciones creadas hace más de 30 días. Esta acción NO se puede deshacer." con botón confirmar.
  - Función `bulkDestroyInactive()` que llama al endpoint nuevo y muestra toast + recarga approved.

## 9. UI: Atomicity suggestions panel

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Nueva celda expandible en cada fila de aprobadas (o un panel lateral). Click en "Ver atomicidad" expande.
  - Lazy load: llama `GET /ia/correcciones/{id}/atomicity-suggestions` solo cuando se expande.
  - Renderiza dos listas (unigramas, bigramas) con checkboxes + input `correct` inline.
  - Botón "Agregar N como nuevas correcciones" que llama al endpoint bulk-add.
  - Marcar visualmente los candidatos que ya están en el diccionario ("ya existe").

## 10. Re-prompting atómico del AI suggest

- [ ] En `app/app/Services/Ia/LlmCorrectionSuggester.php`:
  - Modificar `getSystemPrompt()` para incluir la sección "REGLA DE ATOMICIDAD" según design.md.
  - Renombrar `looksLikeBrandOrProperNoun(string $wrong): bool` a `evaluateCandidate(string $wrong, ?int $freq = null): array` que retorna `['accept' => bool, 'reason' => ?string]`. Mantener el método viejo como wrapper deprecated que llama al nuevo (para no romper otros callers).
  - El método `suggest()` ahora cuenta: `rejected_by_filter` (marcas/siglas), `rejected_by_length` (longitud), `promoted_to_atomic` (candidatos extraídos).
  - Si el JSON del LLM incluye `atomic_candidates`, parsearlo e insertarlos con source='ai-suggest-YYYY-MM-DD-atomic-from-{parent_candidate_id}'.
- [ ] `php -l` validar.
- [ ] Verificar que el command `corrections:ai-suggest` reporta los nuevos counters en su output final.

## 10b. Context-shift protection (risk_level)

### 10b.1 Migración

- [ ] Crear migración `app/database/migrations/2026_08_02_xxxxxx_add_risk_level_to_corrections.php`:
  - `ALTER TABLE corrections ADD COLUMN risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'low' AFTER source_segment_id`.
  - `INDEX corrections_risk_level_index (risk_level)`.
- [ ] `php artisan migrate` (validar que corre limpio).
- [ ] Verificar con `SHOW CREATE TABLE corrections` que la columna quedó.

### 10b.2 Blocklist estática

- [ ] En `app/config/corrections.php`, agregar clave `context_sensitive.terms` con `filler_words` (11 entradas) y `false_friends` (7 entradas) según design.md.
- [ ] `php -l` validar.

### 10b.3 ContextShiftAuditor service

- [ ] Crear `app/app/Services/Ia/ContextShiftAuditor.php`:
  - `audit(int $limit = 0): array` con chunking de 500.
  - `evaluateOne(Correction $r, array $config): ?array` con lógica false-friends-first, luego fillers.
  - `applyToDb(bool $dryRun = true): array` que skipea overrides manuales (`risk_level != 'low'`).
- [ ] `php -l` validar.

### 10b.4 CLI context-audit

- [ ] Crear `app/app/Console/Commands/ContextAuditCommand.php`:
  - Signature: `corrections:context-audit {--apply}`.
  - Sin `--apply`: dry-run, imprime "N correcciones a marcar" con tabla de cambios propuestos (id, wrong, risk_sugerido, reason).
  - Con `--apply`: ejecuta `applyToDb(false)` y reporta `updated, skipped_manual`.
- [ ] `php -l` validar.
- [ ] Probar `php artisan corrections:context-audit` (dry-run) sobre el diccionario actual — debe sugerir las 75 que detectamos hoy.

### 10b.5 Model: Correction

- [ ] En `app/app/Models/Correction.php`:
  - Agregar `risk_level` a `$fillable`.
  - Agregar `risk_level` a `$casts` como string.
  - Agregar scope `safe(Builder $q): Builder` que filtra `where('risk_level', '!=', 'high')`.
  - Modificar `applyToText(string $text, bool $includeHighRisk = false): string`:
    - Query usa `safe()` cuando `$includeHighRisk=false`.
    - Orden por LENGTH DESC igual que antes.
- [ ] Grep en todo el proyecto llamadas a `applyToText(` para confirmar que ninguna pasa argumentos extra.
- [ ] `php -l` validar.

### 10b.6 Command ApplyCorrectionsCommand

- [ ] En `app/app/Console/Commands/ApplyCorrectionsCommand.php`:
  - Agregar flag `--include-high-risk` (default false).
  - Pasar el flag al `Correction::applyToText($text, $includeHighRisk)`.
- [ ] `php -l` validar.

### 10b.7 Pre-approval safeguard en controller

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `approve(int $id, ...)` y `store(Request, ...)` ahora retornan `{correction: ..., context_warning?: ...}` cuando `ContextShiftAuditor::evaluateOne()` detecta un patrón.
  - Refactor con helper privado `attachContextWarning(Correction $c): array` para no duplicar lógica.
- [ ] `php -l` validar.

### 10b.8 Rutas nuevas

- [ ] En `app/routes/web.php`:
  - `Route::get('/correcciones/context-audit', ...)` → `contextAudit()` (dry-run, retorna JSON con sugerencias).
  - `Route::post('/correcciones/context-audit', ...)` → `contextAuditApply()` (ejecuta `applyToDb(false)`).

### 10b.9 Controller context-audit endpoints

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `+contextAudit(ContextShiftAuditor $auditor): JsonResponse` retorna JSON con `suggestions: [{id, current_risk, suggested_risk, reason}]`.
  - `+contextAuditApply(Request $request, ContextShiftAuditor $auditor): JsonResponse` retorna `{updated, skipped_manual}`.

### 10b.10 UI: tab "Contexto sensible"

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Nueva tab "Contexto sensible" entre Aprobadas e IA Suggest. Counter muestra N reglas risk=high + risk=medium.
  - Alpine state nuevo: `contextSensitive: { list: [], filter: 'all' }` (filter: 'all' | 'high' | 'medium').
  - Fetch on tab switch: `GET /correcciones/context-audit` carga la lista.
  - Render: tabla con columnas (id, original, corrección, risk actual, razón, acciones).
  - Acciones:
    - Botón "Cambiar a low" → PATCH local (no endpoint necesario, solo cambia el state; en el back se persiste con un endpoint nuevo si se requiere — usar `POST /correcciones/{id}/risk-level` simple).
    - Botón "Editar" → abre modal con form de edición de `wrong_text`/`correct_text`.
    - Botón "Eliminar" → usa `bulkDestroy` con 1 id.
  - Botón global "Aplicar sugerencias del auditor" llama `contextAuditApply()` y muestra toast + recarga.

### 10b.11 UI: badge risk_level + modal context_warning

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - En la tab Aprobadas, agregar columna/badge al lado del `applies_count` con color según risk_level (verde low, amarillo medium, rojo high).
  - Al aprobar una nueva corrección vía modal "Nueva corrección" o bulk approve, si el backend devuelve `context_warning`, mostrar modal de confirmación:
    - "Esta corrección contiene `you know` (muletilla). ¿Seguro que quieres traducirla en todos los contextos?"
    - Botones: "Confirmar de todos modos" / "Cancelar y revisar".

### 10b.12 Backfill en deploy

- [ ] Documentar en sección "Verificación manual" del deploy:
  ```bash
  php artisan migrate
  php artisan corrections:context-audit          # dry-run: confirmar N esperado
  php artisan corrections:context-audit --apply  # backfill
  php artisan corrections:dictionary-audit        # snapshot final
  ```
- [ ] Verificar que `corrections:apply-run --include-high-risk` (sin el flag) NO aplica las reglas risk=high.

## 11. Tests

- [ ] Crear `app/tests/Feature/DictionaryAuditTest.php`:
  - test_totals_match_approved_pending_rejected
  - test_effectiveness_distribution_buckets
  - test_top_unigrams_orders_by_count
  - test_top_bigrams_orders_by_count
  - test_duplicates_returns_zero_for_clean_dict
- [ ] Crear `app/tests/Feature/ContextShiftAuditorTest.php`:
  - test_detects_actually_to_actualmente_as_high_risk (false friend)
  - test_detects_eventually_to_finalmente_as_high_risk
  - test_detects_like_in_wrong_as_high_risk (filler)
  - test_detects_you_know_as_high_risk
  - test_basically_returns_medium_risk
  - test_returns_null_for_safe_corrections
  - test_apply_to_db_dry_run_does_not_persist
  - test_apply_to_db_skips_manual_overrides
- [ ] Crear `app/tests/Feature/CorreccionesRiskLevelTest.php`:
  - test_apply_to_text_skips_high_risk_by_default
  - test_apply_to_text_includes_high_risk_when_requested
  - test_safe_scope_excludes_high_risk
  - test_approve_returns_context_warning_when_filler_detected
- [ ] Crear `app/tests/Feature/AtomicitySuggestionsTest.php`:
  - test_extracts_unigrams_from_simple_phrase
  - test_filters_stopwords_and_short_tokens
  - test_dedupes_against_existing_dictionary
  - test_confidence_high_when_consensus_above_80
  - test_confidence_low_when_consensus_below_80
  - test_caps_results_at_max
- [ ] Crear `app/tests/Feature/BulkDestroyInactiveTest.php`:
  - test_returns_zero_when_no_candidates
  - test_only_inactive_zero_applies_count
  - test_respects_min_age_days
  - test_respects_max_count_cap
  - test_registers_in_correction_bulk_actions
- [ ] Crear `app/tests/Feature/CorreccionesEffectivenessUITest.php`:
  - test_sort_by_applies_count_orders_correctly
  - test_inactive_filter_excludes_nonzero
  - test_inactive_bulk_destroy_endpoint_exists
- [ ] Suite passing: 110 anteriores + ~25 nuevos ≈ 135.

## 12. Spec deltas

- [ ] Crear `openspec/changes/2026-08-02-corrections-dictionary-atomicity/specs/transcription-corrections/spec.md` con 4 ADDED requirements:
  - **Requirement: Admin can prune inactive correction rules in bulk**
  - **Requirement: Admin can see atomicity suggestions for any approved correction**
  - **Requirement: LLM suggester prefers atomic (unigram/bigram) candidates over long phrases**
  - **Requirement: System flags context-shifting corrections and excludes them from automatic application**

## 13. Verificación manual end-to-end

- [ ] `php artisan corrections:dictionary-audit` imprime el reporte. Comparar antes/después.
- [ ] UI: entrar a `/ia/correcciones` → tab Aprobadas → sort por Aplicaciones desc → confirmar que el top son las palabras sueltas.
- [ ] UI: filtrar por inactivas > 30 días → click "Eliminar N inactivas" → confirmar modal → toast verde → lista recarga sin esas filas.
- [ ] UI: expandir una corrección de frase larga → ver panel atomicity con unigramas/bigramas propuestos → agregar 2 → toast verde → confirmar que aparecen en la lista de approved con source='atomicity-from-{id}'.
- [ ] `php artisan corrections:ai-suggest --days=1 --dry-run` → comparar cantidad de unigramas vs frases largas vs corrida anterior.

## 14. Resumen de archivos

### Nuevos
- `app/app/Services/Ia/DictionaryAudit.php`
- `app/app/Services/Ia/ContextShiftAuditor.php`
- `app/app/Console/Commands/DictionaryAuditCommand.php`
- `app/app/Console/Commands/ContextAuditCommand.php`
- `app/database/migrations/2026_08_02_xxxxxx_add_risk_level_to_corrections.php`
- `app/tests/Feature/DictionaryAuditTest.php`
- `app/tests/Feature/AtomicitySuggestionsTest.php`
- `app/tests/Feature/BulkDestroyInactiveTest.php`
- `app/tests/Feature/CorreccionesEffectivenessUITest.php`
- `app/tests/Feature/ContextShiftAuditorTest.php`
- `app/tests/Feature/CorreccionesRiskLevelTest.php`
- `openspec/changes/2026-08-02-corrections-dictionary-atomicity/specs/transcription-corrections/spec.md`

### Modificados
- `app/app/Services/Ia/CorrectionService.php` (+`extractAtomicitySuggestions()`, +`bulkDestroyInactive()`)
- `app/app/Services/Ia/LlmCorrectionSuggester.php` (prompt atómico + `evaluateCandidate()` + `atomic_candidates`)
- `app/app/Models/Correction.php` (+`risk_level` fillable, +scope `safe()`, `applyToText($text, $includeHighRisk=false)`)
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (+5 métodos: atomicity, bulk-destroy-inactive, audit, context-audit, context-audit-apply; modify approve()/store() para context_warning)
- `app/routes/web.php` (+5 rutas)
- `app/routes/console.php` (sin cambios — la migración no afecta scheduler)
- `app/resources/views/ia/correcciones/index.blade.php` (sort, filtros, panel atomicity, modal cleanup, tab contexto sensible, badge risk, modal context_warning en approve)
- `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php` (reportar `rejected_by_length` y `promoted_to_atomic`)
- `app/app/Console/Commands/ApplyCorrectionsCommand.php` (+flag `--include-high-risk`)
- `app/config/corrections.php` (+`context_sensitive.terms` con filler_words y false_friends)

### Sin cambios
- Schema de BD existente salvo la nueva columna `risk_level`.
- Modelos existentes fuera de `Correction`.
- Tests anteriores (compatibles, salvo el de `looksLikeBrandOrProperNoun` que se ajusta al wrapper deprecated).
