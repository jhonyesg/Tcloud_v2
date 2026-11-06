## Purpose

Garantiza que un share creado sobre un folder apunte siempre al canónico y que la página pública del share liste archivos desde cualquier storage que contenga el mismo path físico, no solo del storage del row apuntado.

## Requirements

### Requirement: Public share page lists children across storages

When a public visitor opens `GET /s/{token}` and the share's file is a folder, the rendered listing MUST include files whose `parent_id` is registered in any storage whose `base_path` is a prefix of the folder's physical path, deduplicated by `(file_id, name)`.

#### Scenario: Folder with cross-storage children shows all

- **WHEN** a share points to a folder in a storage whose `base_path` is a prefix of the physical path where the files live, and the files are cataloged in a sub-storage
- **THEN** the rendered HTML MUST list those files
- **AND** the response MUST NOT 500

#### Scenario: Folder with same-storage children shows only its own

- **WHEN** a share points to a folder with children in the same storage
- **THEN** the rendered listing MUST NOT include duplicates from other storages
- **AND** the count MUST match the canonical storage's children count

#### Scenario: Empty folder shows empty page gracefully

- **WHEN** a share points to a folder with zero children in any storage
- **THEN** the listing MUST render with an empty state
- **AND** the response MUST be HTTP 200 (not 404 or 500)

### Requirement: Subfolder navigation within a share uses cross-storage listing

When a public visitor navigates `GET /s/{token}/folder/{folderId}` (subfolder within a share), the subfolder listing MUST use the same cross-storage logic as the root listing.

#### Scenario: Subfolder under share resolves across storages

- **WHEN** a visitor navigates from a share into a subfolder that is cataloged in a different storage
- **THEN** the subfolder MUST be resolved against the same hierarchy used by the root listing
- **AND** the listing MUST show cross-storage children

### Requirement: Folder listing returns exactly one level of children, no grandchildren

When a public visitor opens a folder share and the system computes the visible children of that folder, the listing MUST include only rows whose `parent_id` matches the share's folder id or the id of an equivalent folder row resolved from the hierarchy. The listing MUST NOT include rows whose `parent_id` is a child of those rows (i.e., MUST NOT include grandchildren).

#### Scenario: Canonical folder with sub-folder children returns sub-folders only

- **WHEN** a share points to a folder whose direct children are sub-folders and those sub-folders collectively contain thousands of files
- **THEN** the rendered listing MUST contain exactly those direct children
- **AND** the listing MUST NOT contain any file whose `parent_id` is one of those sub-folders

#### Scenario: Canonical folder with file children is unchanged by the fix

- **WHEN** a share points to a folder whose direct children are files and no sub-folders
- **THEN** the rendered listing MUST contain exactly those direct children
- **AND** the listing MUST NOT contain rows from unrelated parents whose `id` collides with any of the file ids

#### Scenario: Folder with no children anywhere shows empty gracefully

- **WHEN** a share points to a folder that has zero direct children and zero equivalent rows
- **THEN** the response MUST be HTTP 200 with an empty listing
- **AND** the page MUST render the "no hay elementos" empty state

#### Scenario: Mirror with file children returns only those files

- **WHEN** a share points to an equivalent folder row that has file children directly under it and the folder of the share itself has zero children
- **THEN** the rendered listing MUST contain exactly those file children
- **AND** MUST NOT include rows whose `parent_id` resolves to children of that equivalent row (none exist in this case)

#### Scenario: Canonical with mirror that has its own file children returns union

- **WHEN** a share points to a folder with 0 direct children and an equivalent row that has file children of its own
- **THEN** the rendered listing MUST contain exactly those file children
- **AND** the count MUST equal the union of `parent_id = folder_id` and `parent_id = equivalent_id`, nothing more

#### Scenario: Listing count equals direct children plus mirror children exactly

- **WHEN** a share's folder has N direct children and M direct children of each equivalent row (sum across equivalent rows)
- **THEN** after deduplication by name, the listing count MUST equal the size of the deduplicated set
- **AND** the listing MUST NOT exceed N + M (no grandchildren)
- **AND** the listing MUST NOT be less than min(N, M) when names overlap (dedup can reduce but must not zero out)

### Requirement: Cross-storage actions within a share succeed

When a public visitor of a folder share performs any individual action on a file that was rendered in the listing via cross-storage resolution (i.e., the file row lives in a different storage from the share's folder row but represents the same physical location on disk), the action MUST succeed and return the file's content. The system MUST NOT reject the action with 403 `File not in shared folder`.

This invariant applies to: `GET /s/{token}/media/{file_id}/preview`, `GET /s/{token}/preview/{file_id}`, `GET /s/{token}/download/{file_id}`, plus the other methods in `PublicShareController` that internally call `isDescendantOf()`.

#### Scenario: Media preview on cross-storage file succeeds

- **WHEN** a share's folder row lives in a storage whose `base_path` is a prefix of the physical path of a file row cataloged in a sub-storage, and the visitor requests `GET /s/{token}/media/{file_id}/preview`
- **THEN** the response MUST be HTTP 200 with the file's mime type
- **AND** the body MUST be the file bytes streamed from disk
- **AND** the response MUST NOT be HTTP 403 or HTTP 404

#### Scenario: Download on cross-storage file returns the bytes

- **WHEN** the same setup as above, and the visitor clicks the download icon triggering `GET /s/{token}/download/{file_id}`
- **THEN** the response MUST be HTTP 200
- **AND** the `Content-Disposition` header MUST include `attachment; filename="..."` with the file's name
- **AND** the body MUST match `filesize()` of the physical file

#### Scenario: Preview on cross-storage file renders HTML

- **WHEN** the same setup as above, and the visitor requests `GET /s/{token}/preview/{file_id}`
- **THEN** the response MUST be HTTP 200 with `Content-Type: text/html`
- **AND** the response MUST NOT be HTTP 403 JSON

#### Scenario: isDescendantOf recognizes physical-path equivalence

- **WHEN** a folder row F lives in a storage whose `base_path` is a prefix of the physical path of a file row X cataloged in another storage, and the `parent_id` chain of F does not contain X
- **THEN** the descendant check MUST return `true`
- **AND** the check MUST keep returning `false` for files in an unrelated physical location

#### Scenario: File in totally different physical location still gets 403

- **WHEN** a file row X lives in a completely unrelated physical path (does not start with the share's folder's physical path)
- **THEN** the descendant check MUST return `false`
- **AND** `mediaPreview`/`preview`/`download` MUST return HTTP 403

#### Scenario: Defense-in-depth canonicalizes $file before reading disk

- **WHEN** a visitor requests `mediaPreview`/`preview`/`download` and the file row passed by the client is not the one cataloged in the share's own storage
- **THEN** the controller MUST resolve the effective storage and physical path from the hierarchy before computing the absolute path
- **AND** it MUST NOT crash when the hierarchy cannot be resolved
- **AND** it MUST continue with the original file row when the resolution cannot be completed
