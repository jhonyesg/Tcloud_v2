## MODIFIED Requirements

### Requirement: Soft-trash hides the item from listings

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
- **THEN** the trashed item MUST NOT appear in the listing — neither in root listings (`whereNull('parent_id')`) nor in any subfolder listing
- **AND** the user can find it via the dedicated "Papelera" view

#### Scenario: File browser query filters is_trashed=false
- **WHEN** any client (browser or AJAX) requests `/files` listing JSON
- **THEN** the underlying query MUST include `WHERE is_trashed = false` so trashed rows are excluded regardless of `parent_id` filter or cache state
