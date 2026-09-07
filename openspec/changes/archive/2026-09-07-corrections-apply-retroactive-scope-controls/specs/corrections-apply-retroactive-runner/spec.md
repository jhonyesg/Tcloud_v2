## ADDED Requirements

### Requirement: Time scope supports hours and days
The system SHALL accept a Re-aplicar time scope expressed in mixed hours/days granularity. The UI dropdown MUST offer at least `1h, 8h, 1d, 3d, 7d, 14d, 30d, all`. Internally the controller MUST translate the dropdown value to an ISO timestamp (`since`) passed to the worker, replacing the integer `days_back` field for new requests. Existing requests that pass `days_back` (legacy callers) MUST continue to work unchanged.

#### Scenario: Admin picks "1h"
- **WHEN** the admin opens Re-aplicar, selects `1h` and confirms
- **THEN** the worker applies corrections to transcription segments whose `created_at` is within the last hour, regardless of the historical day's segment distribution

#### Scenario: Admin picks "8h" for a regla recién aprobada
- **WHEN** the admin approves a new correction and immediately triggers Re-aplicar with `8h`
- **THEN** the worker touches only segments from the last 8 hours, completing in seconds to minutes instead of hours

#### Scenario: Legacy CLI with --days
- **WHEN** an existing CLI invocation passes `--days=7` (no `--correction-id`)
- **THEN** the worker runs as before, applying all approved corrections to segments from the last 7 days

### Requirement: Per-correction selection filter
The system SHALL let the admin apply corrections only to a chosen subset of approved corrections, identified by their `corrections.id`. The endpoint MUST accept `correction_ids: int[]` (UI form) and the artisan command MUST accept repeated `--correction-id=` flags (CLI form). When `correction_ids` is empty, the system applies ALL approved corrections (backward-compat).

#### Scenario: Admin selects 3 correcciones from the Aprobadas table
- **WHEN** the admin checks 3 rows in the Aprobadas table, opens Re-aplicar, picks "Aplicar solo las correcciones seleccionadas (3)" with `1h`, and confirms
- **THEN** the worker applies only those 3 corrections to segments from the last hour; other 2492 approved corrections are ignored during this run

#### Scenario: Admin tries to apply a non-approved correction
- **WHEN** the request body includes a `correction_ids` array containing a pending or rejected id
- **THEN** the controller returns HTTP 422 with a message identifying the rejected ids, and the run is not launched

#### Scenario: Admin tries to apply an empty selection
- **WHEN** the admin confirms Re-aplicar with "solo seleccionadas" but no row is checked
- **THEN** the UI prevents confirmation (button disabled or warning) AND the controller, if hit directly, returns HTTP 422 with a legible error

### Requirement: Impact preview before launching
The system SHALL provide a non-destructive preview endpoint that, given the same scope parameters as the real run (time window + correction_ids), returns the projected counts WITHOUT launching the worker or writing to the cache. The UI MUST show the preview when the admin clicks "Vista previa" inside the Re-aplicar modal, before they click "Confirmar y aplicar".

#### Scenario: Admin previews "all" with all corrections
- **WHEN** the admin clicks "Vista previa" with scope `all` and "Aplicar todo el diccionario"
- **THEN** the response includes `{ segments_total: 585240, corrections_total: 2495, estimated_minutes: 360 }` (numbers from a real query, not hardcoded)

#### Scenario: Admin previews "1h" with 3 selected corrections
- **WHEN** the admin clicks "Vista previa" with scope `1h` and 3 selected corrections
- **THEN** the response includes `{ segments_total: <real count of segments in last hour>, corrections_total: 3, estimated_minutes: 1 }`

#### Scenario: Preview is non-destructive
- **WHEN** the preview endpoint is called
- **THEN** no worker is spawned, no cache key is written under `corrections_apply:*`, and no log marker is appended to `/tmp/kilo_artisan_bg.log`
