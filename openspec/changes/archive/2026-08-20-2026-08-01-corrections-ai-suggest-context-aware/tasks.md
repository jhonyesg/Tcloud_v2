# Tasks: AI-powered corrector suggester con contexto y exclusión de marcas

## 1. Config layer

- [ ] Crear `app/config/llm-correction.php` con: enabled, provider, base_url, api_key, model, timeout, max_tokens, temperature, sample_size_default, days_back_default, prompt_version, protected_brands.
- [ ] Agregar entradas en `app/.env.example` (`LLM_*`).
- [ ] `php -l` validar.

## 2. LLM service

- [ ] Crear `app/app/Services/Concerns/CallsLlmChatCompletion.php` (trait) — POST a `/chat/completions` OpenAI-compatible, retorna array.
- [ ] Crear `app/app/Services/Ia/LlmCorrectionSuggester.php`:
  - `sampleSegments(int $days, int $sampleSize): array`
  - `alreadyProcessedToday(int $segmentId): bool` + `markProcessedToday()` (cache)
  - `getSystemPrompt(): string` (versionado por `prompt_version`)
  - `buildUserPrompt(array $segments): string`
  - `callLlm(string $system, string $user): array` (usa el trait)
  - `looksLikeBrandOrProperNoun(string $wrong): bool` (post-filtro)
  - `suggest(int $days, int $sampleSize): array` (punto de entrada unificado)
- [ ] `php -l` validar syntax.

## 3. Service wrapper

- [ ] En `app/app/Services/Ia/CorrectionService.php`:
  - Agregar `aiSuggestEnEsMix(int $days, int $sampleSize, User $by): array`.
  - Llama `LlmCorrectionSuggester`, filtra duplicados pending, llama `propose()` por cada uno, source='ai-suggest-YYYY-MM-DD'.
- [ ] `php -l` validar.

## 4. Artisan command

- [ ] Crear `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php`:
  - Signature: `corrections:ai-suggest {--days=} {--sample=} {--dry-run}`.
  - Si `enabled=false`: sale con warn.
  - Si falta `api_key`: FAILURE con mensaje claro.
  - Dry-run: tabla de candidatos + rechazados por filtro.
  - Real: counts mined/inserted/skipped/rejected.
- [ ] `php -l` validar.
- [ ] Verificar `php artisan list` lo registra.

## 5. Scheduling cada 4h

- [ ] En `app/routes/console.php`, agregar:
  ```php
  Schedule::command('corrections:ai-suggest')
      ->everyFourHours()
      ->withoutOverlapping(120)
      ->name('corrections:ai-suggest-scheduled');
  ```

## 6. Controller endpoint + ruta

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Agregar `aiSuggestStatus()` que retorna JSON con `last_ai_suggest_at` y `pending_from_ai_suggest`.
- [ ] Ruta `GET /ia/correcciones/ai-suggest-status`.

## 7. UI: badge AI-suggest

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Segundo renglón en header para "AI Suggest".
  - Variables Alpine: `aiSuggestStatus`, `aiSuggestLabel`, `aiSuggestBadgeClass`.
  - Color: verde < 12h, amarillo 12-24h, rojo > 24h.
  - Carga en `init()` vía `loadAiSuggestStatus()`.

## 8. Kilo skill

- [ ] Crear `app/.kilocode/skills/corrections-ai-suggest/SKILL.md`:
  - Cuándo cargar (triggers para que Kilo sepa cuándo aplicar).
  - Comandos CLI exactos.
  - Output esperado y cómo interpretarlo.
  - Configuración (env vars).

## 9. Tests

- [ ] Crear `app/tests/Feature/LlmCorrectionSuggesterTest.php`:
  - test_looks_like_brand_or_proper_noun_detects_all_caps_sigla
  - test_looks_like_brand_or_proper_noun_detects_known_brand (Dionato, Word Enterprise)
  - test_looks_like_brand_or_proper_noun_detects_internal_capitalization (MacBook, iPhone)
  - test_looks_like_brand_or_proper_noun_allows_lowercase_english_phrase (in the world)
  - test_prompt_system_message_contains_brand_exclusion_rules
  - test_call_llm_throws_on_http_error (mocking Http facade)
  - test_call_llm_parses_json_response (mocking Http)
  - test_call_llm_handles_invalid_json (mocking Http)
- [ ] Crear `app/tests/Feature/AiSuggestCommandTest.php`:
  - test_command_signature_has_options
  - test_command_skips_when_disabled
  - test_command_errors_when_api_key_missing
  - test_idempotency_second_run_does_not_duplicate
- [ ] Suite passing: 33 anteriores + ~12 nuevos ≈ 45.

## 10. Verificación manual

- [ ] Dry-run con `LLM_API_KEY` configurada: el LLM responde con JSON válido.
- [ ] Verificar que el system prompt aparece completo en logs (debug).
- [ ] Inspeccionar manualmente los candidatos retornados; asegurar que NO contienen marcas (Dionato, Word Enterprise, etc.).
- [ ] Insert real: confirmar que aparecen en `/ia/correcciones` con source='ai-suggest-YYYY-MM-DD'.
- [ ] Badge "AI Suggest" muestra "Última: hace Xh (N por aprobar)" con color correcto.

## 11. Spec deltas

- [ ] Crear `openspec/changes/2026-08-01-corrections-ai-suggest-context-aware/specs/llm-correction-suggestion/spec.md` (spec nuevo):
  - ADDED: Requirement: System can suggest corrections using an LLM with brand exclusion rules
  - ADDED: Requirement: Admin can trigger an AI-suggest pass on demand
  - ADDED: Requirement: AI-suggest runs every 4 hours via scheduler
  - ADDED: Requirement: Brand and proper-noun exclusion is enforced (defense-in-depth)
- [ ] Editar `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md`:
  - ADDED: Requirement: System can generate corrections using LLM-powered suggester (alias del spec nuevo)

## 12. Resumen de archivos

### Nuevos
- `app/app/Services/Concerns/CallsLlmChatCompletion.php`
- `app/app/Services/Ia/LlmCorrectionSuggester.php`
- `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php`
- `app/config/llm-correction.php`
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (método nuevo)
- `app/.kilocode/skills/corrections-ai-suggest/SKILL.md`
- `app/tests/Feature/LlmCorrectionSuggesterTest.php`
- `app/tests/Feature/AiSuggestCommandTest.php`
- `openspec/changes/2026-08-01-corrections-ai-suggest-context-aware/specs/llm-correction-suggestion/spec.md`

### Modificados
- `app/app/Services/Ia/CorrectionService.php` (+`aiSuggestEnEsMix()`)
- `app/routes/web.php` (+ruta)
- `app/routes/console.php` (+schedule cada 4h)
- `app/resources/views/ia/correcciones/index.blade.php` (segundo badge)
- `app/.env.example` (LLM_* vars)

### Sin cambios
- Schema de BD (no migrations).
- Modelos existentes.
- Tests anteriores (compatibles).
