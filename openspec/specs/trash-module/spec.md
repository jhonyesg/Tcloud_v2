# trash-module Specification

## Purpose
Permite a los usuarios deshacer eliminaciones accidentales moviendo el contenido a una papelera temporal en lugar de borrarlo de inmediato, y limpia automáticamente lo que supere la retención configurada.

## Requirements

### Requirement: Soft-trash on user delete

The system MUST mark a File as trashed (instead of hard-deleting the row and its on-disk contents) when an authorized user requests deletion through the file browser. The trash state consists of: `is_trashed=true`, `deleted_at=now()`, `original_parent_id=<previous parent_id>`, `parent_id=NULL`.

#### Scenario: User deletes a file from the browser
- **WHEN** an authenticated user with delete permission on a file issues the delete action
- **THEN** the file row's `is_trashed` becomes true, `deleted_at` is set, the original parent is recorded, and `parent_id` is nulled
- **AND** the on-disk file is NOT moved

#### Scenario: User deletes a folder
- **WHEN** an authenticated user deletes a folder
- **THEN** the folder row is soft-trashed
- **AND** every descendant file and folder row is also soft-trashed with its own `deleted_at`, `original_parent_id`, and `parent_id=NULL`

#### Scenario: Soft-trash hides the item from listings
- **WHEN** the file browser loads a folder listing after an item is soft-trashed
- **THEN** the trashed item MUST NOT appear in the listing
- **AND** the user can find it via the dedicated "Papelera" view

### Requirement: Restore semantics

The system MUST let the original owner (or admin) restore a trashed item. Restoration MUST attempt to place the item back under its `original_parent_id`; if that parent is missing or also trashed, the item MUST be restored to the root of its storage provider. If a name collision exists at the destination, the system MUST suffix the restored name with `-restored-<unix_timestamp>`.

#### Scenario: Restore with original parent still present
- **WHEN** user restores a trashed file whose `original_parent_id` points to a non-trashed folder
- **THEN** the file's `parent_id` is set back to `original_parent_id`, `is_trashed=false`, `deleted_at=NULL`, `original_parent_id=NULL`

#### Scenario: Restore with original parent missing
- **WHEN** user restores a trashed file whose `original_parent_id` no longer resolves to an existing folder
- **THEN** the file is placed at the root of its storage provider (`parent_id=NULL` but `is_trashed=false`)

#### Scenario: Restore with name collision
- **WHEN** user restores a file whose destination already has a sibling with the same name
- **THEN** the restored file is renamed with the suffix `-restored-<unix_timestamp>` before insertion

### Requirement: Sync MUST NOT modify trashed rows

The system MUST guarantee that the periodic storage sync (`StorageSyncService::doSyncFolder` and any path it triggers) neither updates, recreates, nor prunes rows whose `is_trashed=true`. The sync MUST treat trashed rows as the canonical representation of their on-disk path for the duration of their trash lifetime.

#### Scenario: Sync encounters a trashed file's path
- **WHEN** a sync scan finds an on-disk file whose path already has a row with `is_trashed=true`
- **THEN** the sync MUST NOT create a duplicate row, MUST NOT update the existing row, and MUST NOT mark it as orphan

#### Scenario: Sync encounters a trashed folder
- **WHEN** a sync scan finds an on-disk folder whose path already has a row with `is_trashed=true`
- **THEN** the sync MUST NOT create a duplicate folder row and MUST NOT recurse into the folder to reconcile its contents

### Requirement: Retention purge cron

The system MUST run a scheduled task (`php artisan trash:purge`) at the cadence configured in `config/trash.php`. The purge MUST hard-delete rows where `is_trashed=true AND deleted_at < NOW() - retention_days`. The purge MUST be guarded against mass-delete pathologies with the same abort-if-ratio-exceeds-threshold pattern used by `SessionService::cleanOrphans`.

#### Scenario: Trashed item past retention is hard-deleted
- **WHEN** the scheduled purge runs and a trashed row's `deleted_at` is older than `config('trash.retention_days')` days
- **THEN** the row and its on-disk contents are hard-deleted via the existing `deleteRecursive` path (without the silenced `@rmdir`)

#### Scenario: Linked items are skipped
- **WHEN** the purge candidate is linked to a transcription, share, or media-edit job (per `StorageSyncService::isFileLinked`)
- **THEN** the purge MUST skip that item, keep it in trash, and log a warning identifying the blocking relationship

#### Scenario: Mass-delete guardrail aborts the run
- **WHEN** the number of purge candidates in a single run exceeds `purge_max_ratio` of the storage's file count
- **THEN** the purge MUST abort before deleting anything and log `trash.purge.aborted_mass_delete` with the candidate count

### Requirement: Trash listing and per-item actions

The system MUST expose a `/papelera` route that lists the authenticated user's trashed items (admins see everyone's). Each row MUST show: name, days remaining until purge, original location hint, and three actions: Restore, Hard-delete, (admin-only) Force-purge-all-of-user. A bulk-select action MUST support Restore-all-selected and Hard-delete-all-selected with a confirmation prompt.

`GET /papelera` from a browser navigation MUST return an HTML Blade view (`papelera.index`) that extends the main layout. The same endpoint MUST return a JSON payload (`{items: [...], pagination: {...}}`) when the request signals an AJAX consumer via `Accept: application/json` or `X-Requested-With: XMLHttpRequest`. The Blade view MUST be discoverable by Laravel's standard view loader (located under `app/resources/views/papelera/index.blade.php`).

#### Scenario: Owner opens their trash
- **WHEN** an authenticated user navigates to `/papelera` (browser request without JSON Accept header)
- **THEN** the response is HTML rendered by the `papelera.index` Blade view, showing only their own trashed items, sorted by `deleted_at DESC`, with each item's "días restantes" calculated as `retention_days - (NOW() - deleted_at)`

#### Scenario: Alpine fetches trash items via JSON
- **WHEN** the `papelera.index` view issues an AJAX fetch to `/papelera?page=N` with `Accept: application/json`
- **THEN** the same endpoint returns the JSON payload `{items: [...], pagination: {page, per_page, total, has_more}}` consumed by the Alpine `loadItems()` flow

#### Scenario: Owner hard-deletes an item
- **WHEN** the owner confirms hard-delete of a trashed item
- **THEN** the system calls `PapeleraService::hardDelete`, removes the row and on-disk content, and refreshes the listing

#### Scenario: Non-owner tries to view or act on someone else's trash
- **WHEN** a non-admin user requests another user's trash listing or restore/hard-delete action
- **THEN** the system MUST return 403 Forbidden

#### Scenario: Empty trash renders the empty-state placeholder
- **WHEN** an authenticated user navigates to `/papelera` and has zero trashed items
- **THEN** the rendered HTML view shows the empty-state placeholder ("La papelera está vacía") with the trash icon, instead of returning a raw JSON dump

### Requirement: Public share link to a trashed file

The system MUST return HTTP 410 Gone with a JSON body identifying that the file is in trash when a public share token resolves to a file with `is_trashed=true`. The response MUST NOT expose the trash view, retention dates, or any other user's data.

#### Scenario: Public visitor opens share link to trashed file
- **WHEN** an unauthenticated visitor hits `/s/{token}` and the resolved file has `is_trashed=true`
- **THEN** the response status is 410 Gone and the body explains the file was moved to trash by its owner

### Requirement: Cache invalidation covers root listing after soft-trash

The system MUST invalidate the `FileController@index` cache for both the file's original parent folder AND for the storage root listing whenever a soft-trash moves the file's `parent_id` from a non-null value to NULL. Without the root-listing invalidation, the cached `whereNull('parent_id')` payload returns the just-trashed row for up to 60 seconds after the delete action, surfacing ghost items in `/files` until the TTL expires. Additionally, the soft-trash endpoint MUST NOT raise an HTTP 500 due to visibility issues when invalidating the sidebar cache — that invalidation happens inside `PapeleraService::softTrash` and MUST NOT be repeated in the controller.

#### Scenario: Trashed item vanishes from root listing immediately
- **WHEN** a user soft-trashes a file whose original parent is a non-root folder (so the post-trash `parent_id` becomes NULL), and another user has loaded the storage's root listing cached
- **THEN** the next request to `/files` for that storage root MUST NOT return the trashed file in the listing payload (cache was invalidated)

#### Scenario: File already at root before trash still works
- **WHEN** a user soft-trashes a file that was already at the storage root (`original_parent_id` is NULL and post-trash `parent_id` is NULL)
- **THEN** the existing single invalidation call covers the root case correctly; no regression

#### Scenario: Soft-trash returns 200 to the client
- **WHEN** a user soft-trashes any file via `DELETE /files/{id}`
- **THEN** the response is 200 OK with body `{message: 'Moved to trash', trashed_id: ...}`, not a 500 server error

### Requirement: How-it-works info panel on the trash view

The `/papelera` view MUST expose a collapsible accordion titled "¿Cómo funciona la papelera?" placed between the page header and the listing (or empty state). The accordion MUST be collapsed by default and MUST be togglable via a button click. When expanded, the panel MUST explain, in plain Spanish, four blocks: (1) what happens internally when a user deletes a file (soft-trash flags, no disk move, no row duplication, child recursion for folders); (2) the retention purge lifecycle including the `trash:purge` daily cron, the configurable retention window, the mass-delete guardrail, and the linked-item protection; (3) the difference between Restore and Hard-delete, including the `-restored-<timestamp>` suffix on name collision and the blocked-delete-when-linked behavior; (4) the quota and public-share implications (storage accounting stays until hard-delete, public share links return 410 Gone while the file is trashed). The visual pattern MUST match the existing "¿Cómo funciona la API del transcriptor?" accordion at `app/resources/views/ia/api-transcriptor/index.blade.php:60-111` (white card, brand-500 `fa-circle-info` icon, chevron rotation on toggle, two-column grid on `md:` breakpoint, `font-mono bg-slate-100` for technical terms).

#### Scenario: User opens the help panel
- **WHEN** an authenticated user clicks the "¿Cómo funciona la papelera?" toggle on `/papelera`
- **THEN** the panel expands and shows the four explanation blocks with technical terms rendered in monospace

#### Scenario: User collapses the help panel
- **WHEN** the user clicks the toggle again while the panel is expanded
- **THEN** the panel collapses back to its header-only state and the chevron rotates back

#### Scenario: Page loads with the panel collapsed
- **WHEN** an authenticated user navigates to `/papelera` for the first time in a session
- **THEN** the help panel is rendered collapsed and does not occupy visual space beyond its header

### Requirement: Sidebar entry with badge

The system MUST render a sidebar entry labeled "Papelera" with a numeric badge showing the count of the current user's trashed items. If any item has fewer than 3 days remaining until purge, the badge MUST use a warning color.

#### Scenario: User with non-empty trash loads any page
- **WHEN** the sidebar renders for a user with at least one trashed item
- **THEN** the "Papelera" entry is visible and the badge displays the total count

#### Scenario: User with empty trash loads any page
- **WHEN** the sidebar renders for a user with zero trashed items
- **THEN** the "Papelera" badge is not rendered (the entry may still be visible as a link)
