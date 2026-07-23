## ADDED Requirements

### Requirement: Empty state SHALL NOT display during active loading
The file manager SHALL NOT show the "No hay archivos" empty state while a file loading request is in progress or while the empty-state delay timer is active.

#### Scenario: Skeleton shows during load, empty state appears after delay
- **WHEN** the user navigates to a storage folder and the server responds with zero files
- **THEN** the system displays the skeleton loader during the fetch, and after the response arrives, waits up to 1500ms before showing the empty state

#### Scenario: Files arrive before delay expires
- **WHEN** the user navigates to a folder and the server responds with files within 1500ms
- **THEN** the empty state timer is cancelled and files are displayed immediately without any empty state flash

### Requirement: Empty state delay timer SHALL reset on each navigation
The system SHALL cancel any pending empty-state delay timer when a new file loading request is initiated (navigation, refresh, or search clear).

#### Scenario: Rapid navigation between folders
- **WHEN** the user navigates to folder A (empty), then quickly navigates to folder B before the 1500ms delay expires
- **THEN** the empty state from folder A is never shown; folder B's loading proceeds normally

#### Scenario: Refresh cancels pending empty state
- **WHEN** the user is viewing an empty folder with the empty state visible and clicks "Actualizar"
- **THEN** the empty state disappears immediately and the skeleton loader shows during the refresh

### Requirement: Backend SHALL NOT cache empty auto-scan results
The `GET /files` endpoint SHALL NOT store empty file arrays in Redis cache when the empty result originated from an auto-scan (syncFolder returning zero files).

#### Scenario: Auto-scan returns empty on NFS timeout
- **WHEN** a user visits a storage folder for the first time and `syncFolder()` returns an empty array due to a transient filesystem error
- **THEN** the empty result is returned to the client but NOT cached in Redis, so the next request triggers a fresh DB query

#### Scenario: Normal empty folder is cached
- **WHEN** a user visits a storage folder that legitimately has no files (DB query returns 0, no auto-scan triggered)
- **THEN** the empty result IS cached with the standard TTL (60s for root, 300s for today, 86400s for older)

### Requirement: Cron sync SHALL invalidate root folder cache
The `storage:sync --all` artisan command SHALL invalidate the Redis cache for the root folder when files are created or deleted during sync.

#### Scenario: Cron adds files to root folder
- **WHEN** the cron sync runs and creates new file records at the root level (parentId=null) of a storage
- **THEN** the root folder cache generation counter is incremented, causing the next request to miss cache and fetch fresh data

#### Scenario: Cron deletes files from root folder
- **WHEN** the cron sync runs and deletes file records from the root level of a storage
- **THEN** the root folder cache generation counter is incremented
