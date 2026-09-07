## Purpose

Define el flujo de **Variation Finder**: herramienta que toma una palabra/frase + un scope temporal y devuelve todas las variantes literales que aparecen en las transcripciones de ese scope, indicando cuáles ya están cubiertas por reglas y permitiendo crear reglas nuevas para las que faltan.

## ADDED Requirements

### Requirement: Variation Finder endpoint accepts a word and time scope
The system SHALL expose `POST /ia/correcciones/variations/find` that accepts `{ word: string, since: ISO8601|null, limit: int (default 100) }` and returns the top variants of that word found in transcription_segments matching the scope, grouped by normalized surrounding context.

#### Scenario: Admin searches "Pellas" with 8h scope
- **WHEN** the admin POSTs `{ word: "Pellas", since: "2026-09-06T20:00:00Z" }`
- **THEN** the endpoint returns up to 100 variants grouped by `substring(text FROM '<word_prefix>{0,40}Pellas[a-záéíóú]*')`, each with `{ variant, count, example_text, example_segment_id, is_approved_rule, is_pending_rule }`

#### Scenario: Admin searches an exact approved word
- **WHEN** the admin POSTs `{ word: "futures of cities", since: null }`
- **THEN** the response includes that exact variant with `is_approved_rule: true` and the existing rule id, plus any other variants found nearby

#### Scenario: Admin provides empty word
- **WHEN** the admin POSTs `{ word: "" }`
- **THEN** the endpoint returns HTTP 422 with `error: "word no puede estar vacío"` — no se ejecuta la query

#### Scenario: Admin provides limit too large
- **WHEN** the admin POSTs `{ limit: 5000 }`
- **THEN** the endpoint caps `limit` to 500 and continues (or returns 422 with a message). Either way, no query of unbounded size runs.

### Requirement: Variant grouping uses a context window
The system SHALL extract, for each matching segment, a window of ±25 characters around the word (configurable via `context_window` parameter, default 25, max 100), then group by that window normalized as `lowercase + trim + collapse whitespace + collapse internal punctuation`.

#### Scenario: Window captures surrounding context
- **WHEN** a segment contains "la llegada del presidente Abelardo de las Pellas anunció"
- **THEN** the variant extracted is "del presidente abelardo de las pellas anunció" (lowercase, trimmed, ±25 chars around "Pellas")

#### Scenario: Same variant in different segments merges into one row
- **WHEN** two different segments both contain "Abelardo de las Pellas" but in slightly different surrounding text
- **THEN** they group into ONE row with `count: 2` and the first segment as `example_segment_id`

### Requirement: Response indicates coverage against existing rules
For each returned variant, the response SHALL indicate whether it already matches an approved or pending rule (by comparing the variant against `corrections.wrong_normalized` after the same normalization).

#### Scenario: Variant is already an approved rule
- **WHEN** the variant "abelardo de las pellas" matches approved rule 10523
- **THEN** the response row has `is_approved_rule: true`, `existing_rule_id: 10523`, `existing_rule_status: "approved"`

#### Scenario: Variant has no existing rule
- **WHEN** the variant has no match in the corrections table
- **THEN** the response row has `is_approved_rule: false`, `is_pending_rule: false`, `existing_rule_id: null`

#### Scenario: Variant matches a pending rule
- **WHEN** the admin already created a pending rule for that variant
- **THEN** the response row has `is_pending_rule: true` and `existing_rule_id: <id>`

### Requirement: UI displays results with creation actions
The Variation Finder tab SHALL display a table with the returned variants, each row showing the variant text, frequency, status badge (Cubierto / Pendiente / Sin regla), and action buttons per the spec.

#### Scenario: Variant without rule shows "Crear corrección" button
- **WHEN** a row has `is_approved_rule: false` and `is_pending_rule: false`
- **THEN** the row shows a "Crear corrección" button that opens a modal with `wrong` pre-filled with the variant and an empty `correct` field

#### Scenario: Variant with approved rule shows link to rule
- **WHEN** a row has `is_approved_rule: true`
- **THEN** the row shows a "Ver regla" link that navigates to the Aprobadas tab with the rule id highlighted

#### Scenario: Bulk selection
- **WHEN** the admin checks multiple rows without rules and clicks "Crear corrección para N"
- **THEN** a single modal opens where the admin enters ONE `correct` value; submitting creates N pending rules in a single transaction, each with the row's variant as `wrong`

### Requirement: Performance is bounded for large scopes
The endpoint SHALL cap its internal query to a maximum of 10000 segment matches (configurable via `MAX_SCAN_SEGMENTS` constant), even for `since: null` (all-time) queries. When the cap is hit, the response includes a `truncated: true` flag and a `next_since` suggestion (oldest `created_at` in the result + 1 second) so the admin can paginate.

#### Scenario: All-time scope with >10k matches
- **WHEN** the admin POSTs `{ word: "Abelardo", since: null }` and there are 131k matches
- **THEN** the response includes `truncated: true`, `next_since: "<oldest result timestamp + 1s>"`, and processes only the first 10000 grouped variants

#### Scenario: Bounded scope returns full results
- **WHEN** the admin POSTs `{ word: "Pellas", since: "2026-09-06T20:00:00Z" }` and there are 200 matches in 8h
- **THEN** the response includes `truncated: false` and processes all 200 matches

### Requirement: Creation writes rules in pending status
The endpoint SHALL create new correction rules with `status: "pending"` (NOT approved) so the admin reviews them via the existing bulk moderation flow before they enter the apply-retroactive pipeline.

#### Scenario: Single creation from variant
- **WHEN** the admin confirms a single "Crear corrección" with `wrong: "Abelardo de las Pelle"` and `correct: "Abelardo de la Espriella"`
- **THEN** the endpoint creates a row with `status: "pending"`, `proposed_by: <admin_id>`, `source: "variation-finder-2026-09-07"` and returns `{ correction_id, status: "pending" }`

#### Scenario: Bulk creation
- **WHEN** the admin confirms bulk creation for 5 variants with one shared `correct: "Abelardo de la Espriella"`
- **THEN** the endpoint creates 5 pending rules in a single DB transaction; if any fails (e.g., duplicate normalized), the whole transaction rolls back and returns HTTP 422 with the conflicting variant name

### Requirement: No IA tokens consumed
The Variation Finder SHALL NOT invoke any LLM. All discovery is pure SQL (`LIKE` match + grouping + aggregate). This is the explicit differentiator from `corrections-ai-suggest` (which is LLM-driven) and `corrections-ai-context-aware-with-mark-curation` (which is LLM-driven per-example).

#### Scenario: Discovery runs without BYOK
- **WHEN** the admin opens Variation Finder with `system_settings.llm-correction.enabled = 0` (the current production default)
- **THEN** the tool works normally because it does not consult any LLM setting
