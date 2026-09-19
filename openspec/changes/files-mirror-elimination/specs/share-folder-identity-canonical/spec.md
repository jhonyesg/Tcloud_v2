## MODIFIED Requirements

### Requirement: Share creation canonicalizes folder file_id

When a user creates a share via `POST /shares` with a `file_id` pointing to a folder, the system MUST persist the `file_id` that is the canonical representation of that folder's physical identity — defined as `lower(rtrim(base_path || '/' || path))`. If the folder's physical identity is represented by more than one row, the system MUST resolve the canonical row (the one whose `base_path` is the most specific) before persisting the share.

#### Scenario: Mirror folder is auto-redirected to canonical
- **WHEN** a `POST /shares` request includes `file_id = 7244491` pointing to a folder in storage 5 whose physical path is also represented by canonical row 7244379 in storage 34
- **THEN** the persisted share MUST have `file_id = 7244379`
- **AND** the response MUST include the resolved canonical file_id, not the original

#### Scenario: Canonical folder passes through unchanged
- **WHEN** a `POST /shares` request includes `file_id` pointing to a folder that is already the canonical row for its physical identity
- **THEN** the share MUST persist with that exact file_id

#### Scenario: File (non-folder) shares are not canonicalized
- **WHEN** a `POST /shares` request includes `file_id` pointing to a file (not folder)
- **THEN** the share MUST persist with the original file_id

#### Scenario: Folder with a single row resolves to itself
- **WHEN** a `POST /shares` request includes a folder whose physical identity has exactly one row in `files`
- **THEN** the share MUST persist with that row's id
- **AND** the resolution MUST NOT fail or return NULL

#### Scenario: Resolution does not depend on the materialized canonical link
- **WHEN** a `POST /shares` request includes a folder whose `canonical_folder_id` is NULL and whose physical identity is shared with another row
- **THEN** the share MUST still be redirected to the row whose `base_path` is the most specific
- **AND** the resolution MUST NOT require `files.canonical_folder_id` to have a value

### Requirement: Subfolder navigation within a share uses cross-storage listing

When a public visitor navigates `GET /s/{token}/folder/{folderId}` (subfolder within a share), the subfolder listing MUST use the same cross-storage logic as the root listing, resolving equivalent folder rows by physical identity rather than by a materialized canonical link.

#### Scenario: Subfolder under share resolves across storages
- **WHEN** a visitor navigates from a share into a subfolder whose physical identity is represented by rows in more than one storage
- **THEN** the subfolder listing MUST resolve the equivalent rows by physical identity before listing contents
- **AND** the listing MUST show cross-storage children
- **AND** the listing MUST NOT depend on `files.canonical_folder_id`

### Requirement: Folder delete via share respects mirror vs canonical

When a share owner deletes the shared folder via `DELETE /s/{token}` or `POST /s/{token}/destroy`, the system MUST distinguish cases based on the share's permissions, and MUST NOT depend on whether the row is a materialized mirror.

#### Scenario: Mirror folder delete is metadata-only
- **WHEN** the share's `file_id` points to a folder that is not the most specific row for its physical identity, and the operator triggers delete
- **THEN** the system MUST delete only the `files` row and the share row
- **AND** the more specific folder row and its children MUST remain untouched
- **AND** no files MUST be removed from disk

#### Scenario: Canonical folder with read permission is metadata-only
- **WHEN** the share's `file_id` points to the most specific folder row for its physical identity, `share.permissions = 'read'`, and the operator triggers delete
- **THEN** the system MUST delete the `files` row and cascade-delete its children rows
- **AND** no files MUST be removed from disk
- **AND** the share row MUST be deleted

#### Scenario: Canonical folder with write or full permission is destructive
- **WHEN** the share's `file_id` points to the most specific folder row for its physical identity, `share.permissions IN ('write','full')`, and the operator triggers delete
- **THEN** the system MUST `deleteRecursive()` the physical folder on disk
- **AND** cascade-delete all `files` rows under it
- **AND** delete the share row
- **AND** the operation MUST be logged in the audit trail

### Requirement: Folder listing returns exactly one level of children, no grandchildren

When a public visitor opens a folder share and the system computes the visible children of that folder, the listing MUST include only rows whose `parent_id` matches one of the folder rows that represent the same physical identity as the shared folder. The listing MUST NOT include rows whose `parent_id` is a child of those rows (i.e., MUST NOT include grandchildren).

#### Scenario: Canonical folder with sub-folder children returns sub-folders only
- **WHEN** a share points to a folder whose direct children are 121 sub-folders and those sub-folders collectively contain thousands of files
- **THEN** the rendered listing MUST contain exactly 121 items
- **AND** the listing MUST NOT contain any file whose `parent_id` is one of those 121 sub-folders

#### Scenario: Canonical folder with file children is unchanged by the fix
- **WHEN** a share points to a folder whose direct children are 34 files (no sub-folders)
- **THEN** the rendered listing MUST contain exactly 34 items
- **AND** the listing MUST NOT include rows from unrelated parents whose `id` collides with any of the 34 file ids

#### Scenario: Mirror with file children returns only those files
- **WHEN** a share points to a less specific row for a physical folder that has 97 file children directly under it and the most specific row has 0 children
- **THEN** the rendered listing MUST contain exactly 97 items (the direct children of that row)
- **AND** MUST NOT include rows whose `parent_id` resolves to children of those children (none exist in this case)

#### Scenario: Canonical with mirror that has its own file children returns union
- **WHEN** a share points to the most specific row for a physical folder with 0 direct children and a less specific equivalent row has 59 file children of its own
- **THEN** the rendered listing MUST contain exactly 59 items
- **AND** the count MUST equal the union of `parent_id = <row A>` and `parent_id = <row B>`, nothing more

#### Scenario: Folder with no children anywhere shows empty gracefully
- **WHEN** a share points to a folder that has zero direct children in any equivalent row
- **THEN** the response MUST be HTTP 200 with an empty listing
- **AND** the page MUST render the "no hay elementos" empty state

#### Scenario: Listing count equals direct children plus mirror children exactly
- **WHEN** a share's folder has N direct children in one equivalent row and M direct children in another equivalent row
- **THEN** after deduplication by name, the listing count MUST equal the size of the deduplicated set
- **AND** the listing MUST NOT exceed N + M (no grandchildren)
- **AND** the listing MUST NOT be zero when N + M > 0

### Requirement: Cross-storage actions within a share succeed

When a public visitor of a folder share performs any individual action on a file that was rendered in the listing via physical-path equivalence (i.e., the file row lives in a different storage from the share's `file_id` row but represents the same physical location on disk), the action MUST succeed and return the file's content. The system MUST NOT reject the action with 403 `File not in shared folder`.

This invariant applies to: `GET /s/{token}/media/{file_id}/preview`, `GET /s/{token}/preview/{file_id}`, `GET /s/{token}/download/{file_id}`, plus the 5 other methods in `PublicShareController` that internally call `isDescendantOf()`.

#### Scenario: Media preview on cross-storage file succeeds
- **WHEN** a share's `file_id` points to a folder in storage A, and the rendered listing includes a file in storage B whose `physical_path_normalized` falls under the folder's `physical_path_normalized`, and the visitor requests `GET /s/{token}/media/{file_id}/preview`
- **THEN** the response MUST be HTTP 200 with `Content-Type: video/mp4` (or the file's mime type)
- **AND** the body MUST be the file bytes streamed from disk
- **AND** the response MUST NOT be HTTP 403 or HTTP 404

#### Scenario: Download on cross-storage file returns the bytes
- **WHEN** the same setup as above, and the visitor clicks the download icon triggering `GET /s/{token}/download/{file_id}`
- **THEN** the response MUST be HTTP 200
- **AND** the `Content-Disposition` header MUST include `attachment; filename="..."` with the file's name
- **AND** the body MUST match `filesize()` of the physical file
- **AND** the response MUST NOT be HTTP 403 with a JSON body saved by the browser as `<id>.json`

#### Scenario: Preview on cross-storage file renders HTML
- **WHEN** the same setup as above, and the visitor requests `GET /s/{token}/preview/{file_id}`
- **THEN** the response MUST be HTTP 200 with `Content-Type: text/html`
- **AND** the response MUST NOT be HTTP 403 JSON

#### Scenario: isDescendantOf recognizes physical-path equivalence
- **WHEN** a folder row F (storage A, `base_path_snapshot` = `/data/root`) and a file row X (storage B, `base_path_snapshot` = `/data/root/sub`) are evaluated, and X's `physical_path_normalized` starts with F's `physical_path_normalized + '/'`
- **THEN** `PublicShareController::isDescendantOf(X, F)` MUST return `true`
- **AND** it MUST also return `true` when F's `parent_id` chain does NOT contain X (the walk-only path is insufficient; physical-path fallback is required)
- **AND** the helper MUST keep returning `false` for unrelated files (different physical prefix)

#### Scenario: File in totally different physical location still gets 403
- **WHEN** a file row X lives in a completely unrelated physical path (does not start with the share's folder's `physical_path_normalized`)
- **THEN** `PublicShareController::isDescendantOf(X, folder)` MUST return `false`
- **AND** `mediaPreview`/`preview`/`download` MUST return HTTP 403

#### Scenario: Defense-in-depth canonicalizes $file before reading disk
- **WHEN** a visitor requests `mediaPreview`/`preview`/`download` and the file row passed by the client is not the most specific row for its physical identity
- **THEN** the controller MUST canonicalize via `FilePhysicalIdentity::canonicalFor()` before computing `$storage` and `$fullPath`
- **AND** if no more specific row exists, the controller MUST continue with the original file row (no crash)
- **AND** the resolved absolute path MUST exist on disk, otherwise the controller MUST log a warning and respond 404

## REMOVED Requirements

### Requirement: Columna renamed para identidad de folder

**Reason**: The requirement describes maintaining `files.canonical_folder_id` (renamed from `merged_into_id`) as the materialized folder identity. The mirror materialization is being retired: physical identity is resolved at read time, so no column-level identity contract remains. The column stays in the schema deprecated for one release but is no longer part of any behavior contract.

**Migration**: Physical identity is now resolved via `lower(rtrim(base_path || '/' || path))`. Call sites that used `File::canonicalFolder()` / `File::isFolderMirror()` MUST resolve equivalence by physical path instead. The column `files.canonical_folder_id` MUST contain 0 non-null rows after the migration and its removal is deferred to a later change.
