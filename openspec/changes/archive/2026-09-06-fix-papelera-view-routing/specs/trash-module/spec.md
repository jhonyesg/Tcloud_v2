## MODIFIED Requirements

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
