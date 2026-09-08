# corrections-variation-finder-ai-suggest Specification

## Purpose
Define el flujo de AI Suggest integrado en el Variation Finder: el LLM agrupa las variantes literales "Sin regla" descubiertas por SQL y propone una normalización canónica (`correct`) por grupo, con el admin decidiendo cuáles aceptar.

## Requirements

### Requirement: AI Suggest endpoint groups variants by canonical correct
The system SHALL expose `POST /ia/correcciones/variations/ai-suggest` that receives the user's variation search context and returns LLM-proposed groupings with canonical corrections.

#### Scenario: Admin clicks "Sugerir correcciones con IA"
- **WHEN** the admin has run findVariations, sees "Sin regla" variants, and clicks the AI Suggest button
- **THEN** the endpoint returns `{ groups: [{ canonical_correct, variants: [{wrong, count, confidence, reason}] }], tokens_used, latency_ms, model }`

#### Scenario: AI groups phonetic typos
- **WHEN** the input contains "Abelardo de las Prieyas", "Abelardo de las Prias", "Abelardo de las Prieya" (all count > 0)
- **THEN** the AI groups them under `canonical_correct: "Abelardo de la Espriella"` with each variant marked as confidence ≥ 0.8

#### Scenario: AI does NOT group unrelated names
- **WHEN** the input contains "Abelardo de las Prias" and "Alberto de las Pellas" (different names entirely)
- **THEN** the AI returns two separate groups, each with confidence < 0.7 to flag uncertainty

### Requirement: AI Suggest respects enabled switch and API key
The endpoint MUST refuse to call the LLM when `system_settings.llm-correction.enabled = 0` or when no BYOK API key is configured. The button in the UI MUST be disabled (with tooltip) when these gates are off.

#### Scenario: Switch OFF
- **WHEN** the admin clicks "Sugerir con IA" with `llm-correction.enabled = 0`
- **THEN** the endpoint returns HTTP 503 with `{ reason: "switch_off", hint: "Activá el switch maestro en IA Suggest para usar AI Suggest en Variation Finder." }`

#### Scenario: No API key
- **WHEN** the admin clicks with switch ON but no BYOK key
- **THEN** the endpoint returns HTTP 503 with `{ reason: "no_api_key", hint: "Configurá la API key en IA Suggest antes." }`

### Requirement: Admin reviews AI suggestions before applying
The UI MUST display AI groups in a modal with checkboxes per variant, pre-selected according to confidence threshold. The admin can edit the canonical_correct per group and choose which groups to apply.

#### Scenario: Variants with confidence ≥ 0.8 are pre-checked
- **WHEN** the AI returns a variant with `confidence: 0.92`
- **THEN** the checkbox for that variant is pre-checked in the UI

#### Scenario: Variants with confidence < 0.8 are NOT pre-checked
- **WHEN** the AI returns a variant with `confidence: 0.55`
- **THEN** the checkbox is unchecked and the row has a warning icon with the reason visible

#### Scenario: Admin edits canonical_correct inline
- **WHEN** the admin changes `canonical_correct: "Abelardo de la Espriella"` to `canonical_correct: "Abelardo Espriella"`
- **THEN** subsequent creation uses the edited value

### Requirement: Bulk creation reuses existing endpoint
When the admin confirms "Crear N correcciones" from the AI modal, the system MUST call the existing `POST /ia/correcciones/variations/bulk-create` endpoint (not a new one) so that transactional rollback on duplicate is preserved.

#### Scenario: Admin confirms 3 groups
- **WHEN** the admin confirms with 3 groups totaling 12 variants marked
- **THEN** the system calls bulk-create once per group (one HTTP request per group) so a duplicate in group 2 doesn't roll back groups 1 and 3

#### Scenario: Duplicate in group 2
- **WHEN** bulk-create for group 2 returns 422 (one variant already exists)
- **THEN** groups 1 and 3 remain applied; group 2 shows an error toast and the modal stays open with the 12 - 5 = 7 successes highlighted

### Requirement: Cost estimation before calling LLM
The endpoint MUST return an estimated token cost before the actual LLM call when called with `{ confirm_cost: true }`. The UI shows this estimate in a confirmation dialog before submitting.

#### Scenario: Admin previews cost
- **WHEN** the admin clicks "Sugerir con IA" with `confirm_cost: true`
- **THEN** the response is `{ estimate: { variants_count: 80, estimated_input_tokens: 1200, estimated_cost_usd: 0.012 } }` (no actual LLM call)

#### Scenario: Admin confirms and runs
- **WHEN** the admin confirms and the system calls with `confirm_cost: false`
- **THEN** the actual LLM call happens and the response includes `tokens_used` and `latency_ms`

### Requirement: Idempotent against partial runs
If the LLM call fails mid-flight (network error, timeout, rate limit), the endpoint MUST return HTTP 503 with a meaningful error and MUST NOT create any partial correction rules.

#### Scenario: LLM timeout
- **WHEN** the LLM call exceeds 30 seconds
- **THEN** the endpoint returns 503 with `{ error: "timeout", detail: "La IA no respondió en 30s. Reintentá." }` and NO bulk-create was issued

#### Scenario: LLM returns invalid JSON
- **WHEN** the LLM response doesn't parse as JSON or is missing required fields
- **THEN** the endpoint returns 503 with `{ error: "parse_failed", raw_excerpt: "first 200 chars..." }` so the admin can debug

### Requirement: No row is created without explicit admin confirmation
The AI Suggest endpoint MUST NOT create correction rules. It only SUGGESTS. Creation happens via bulk-create only after the admin clicks "Confirmar".

#### Scenario: AI Suggest endpoint alone creates nothing
- **WHEN** the admin only calls variations/ai-suggest without calling bulk-create
- **THEN** zero new rows exist in the corrections table
