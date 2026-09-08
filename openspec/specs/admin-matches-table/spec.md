## Purpose

Lets the admin module `ia/avisos-inteligentes/{userId}` show the same grouped-match view that the client sees, populated from the phase-1 data source (`segment_keyword_hits`), and ensures new keywords are matched against all existing transcriptions.

## Requirements

### Requirement: Admin user-detail matches show client data
The system SHALL populate the matches table in `ia/avisos-inteligentes/{userId}` from `segment_keyword_hits` (not the legacy `keyword_matches`), filtered by the same accessibility rules that the client's Histórico uses.

#### Scenario: Admin views matches for a client that has hits
- **WHEN** the authenticated admin opens `ia/avisos-inteligentes/{userId}` for a user with hits in `segment_keyword_hits`
- **THEN** the matches table MUST render those hits (not zero)

#### Scenario: Admin views matches for a client that has no hits
- **WHEN** the client has zero hits in `segment_keyword_hits`
- **THEN** the matches table MUST render an empty state ("Sin coincidencias registradas en los últimos 60 días.")

### Requirement: Admin matches are grouped by (transcription, keyword)
The system SHALL render the matches table in `ia/avisos-inteligentes/{userId}` with one row per `(transcription_id, keyword_id)` pair, NOT one row per individual mention. Each row MUST show the filename, keyword, total mentions count, and snippet of the first mention.

#### Scenario: Single filename with multiple mentions
- **WHEN** the table renders and a filename appears in 5 hits for the same keyword
- **THEN** it MUST be shown as 1 row with ×5 badge, not 5 repeated rows

#### Scenario: Same filename, different keywords
- **WHEN** a filename has hits for "Petro" (×2) and "AV Villas" (×1)
- **THEN** the table MUST show 2 rows, one per keyword (each with its ×N badge)

### Requirement: Admin can expand a group to see individual mentions
The system SHALL provide a way to expand each grouped row and view every individual mention with its timestamp, snippet, and action buttons (Ver, Editor, Archivos).

#### Scenario: Default state
- **WHEN** the matches table first renders
- **THEN** each group row shows only the filename, keyword, total count, and first snippet — the individual mentions are hidden

#### Scenario: Expanding a group
- **WHEN** the admin clicks the expand toggle on a group
- **THEN** the individual mentions for that group become visible, each with its specific minute and snippet

### Requirement: KeywordMatcher idempotency is per (transcription, keyword)
The system SHALL only skip processing a (transcription_id, keyword_id) pair that already has at least one hit in `segment_keyword_hits`. Adding a new keyword SHALL cause a re-scan of all accessible transcriptions.

#### Scenario: First match for new keyword on existing transcription
- **WHEN** a transcription has hits only for keyword A, and a new keyword B is added to a user with access
- **THEN** the next scan cycle SHALL match keyword B against that transcription (because the pair `(transcription_id, keyword_id=B)` has no hits yet)

#### Scenario: Repeated match is prevented by UNIQUE constraint
- **WHEN** the matcher scans a (transcription, keyword) pair that already has hits
- **THEN** `insertOrIgnore` blocks duplicate inserts via the UNIQUE constraint `(transcription_id, segment_id, keyword_id)`

### Requirement: Backfill command for existing keywords
The system SHALL provide an artisan command `mentions:backfill-keyword` that scans accessible transcriptions for a specific keyword (or all keywords with 0 hits in `--all` mode) and inserts any missing hits idempotently.

#### Scenario: Backfill a single keyword
- **WHEN** the admin runs `php artisan mentions:backfill-keyword --keyword=42`
- **THEN** the system scans accessible transcriptions for keyword 42 and inserts hits with the same logic as the live matcher

#### Scenario: Backfill all keywords with 0 hits
- **WHEN** the admin runs `php artisan mentions:backfill-keyword --all`
- **THEN** the system finds all keywords that have 0 hits in `segment_keyword_hits`, and for each one runs the backfill scan

### Requirement: Creating a keyword triggers backfill automatically
The system SHALL dispatch a background job to backfill the new keyword against existing transcriptions when a client creates a keyword via `MisAvisosController::storeKeyword`.

#### Scenario: Client creates a keyword
- **WHEN** POST `/mis-avisos/keywords` creates a new keyword successfully
- **THEN** a `BackfillKeywordMatches` job MUST be dispatched for that keyword, and the HTTP response MUST complete without waiting for the backfill

#### Scenario: Job is idempotent
- **WHEN** the backfill job runs against transcriptions that already have hits for that keyword
- **THEN** `insertOrIgnore` MUST skip those inserts (no duplicates)
