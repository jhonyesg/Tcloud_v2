## Purpose

Garantiza que un share creado sobre un folder apunte siempre al canónico y que la página pública del share liste archivos desde cualquier storage que contenga el mismo path físico, no solo del storage del row apuntado.

## Requirements

### Requirement: Share creation canonicalizes folder file_id

When a user creates a share via `POST /shares` with a `file_id` pointing to a folder that is a mirror (i.e., has `merged_into_id IS NOT NULL`), the system MUST canonicalize the `file_id` to the corresponding canonical folder before persisting the share.

#### Scenario: Mirror folder is auto-redirected to canonical
- **WHEN** a `POST /shares` request includes `file_id = 7244491` (a mirror) whose canonical is `7244379`
- **THEN** the persisted share MUST have `file_id = 7244379`
- **AND** the response MUST include the canonical file_id, not the mirror

#### Scenario: Canonical folder passes through unchanged
- **WHEN** a `POST /shares` request includes `file_id` pointing to a folder with `merged_into_id IS NULL`
- **THEN** the share MUST persist with that exact file_id

#### Scenario: File (non-folder) shares are not canonicalized
- **WHEN** a `POST /shares` request includes `file_id` pointing to a file (not folder)
- **THEN** the share MUST persist with the original file_id regardless of any merged_into_id on the row

### Requirement: Public share page lists children across storages

When a public visitor opens `GET /s/{token}` and the share's file is a folder, the rendered listing MUST include files whose `parent_id` is registered in any storage whose `base_path` is a prefix of the folder's physical path, deduplicated by `(file_id, name)`.

#### Scenario: Folder with cross-storage children shows all
- **WHEN** a share points to canonical folder `7244379` (storage 7, base `/Tcloud/.../Canal_Rcn`) that has zero children in storage 7 but 35 files exist in storage 5 (parent storage) under the same physical path
- **THEN** the rendered HTML MUST list all 35 files
- **AND** the response MUST NOT 500

#### Scenario: Folder with same-storage children shows only its own
- **WHEN** a share points to a folder with children in the same storage
- **THEN** the listing MUST NOT include duplicates from other storages
- **AND** the count MUST match the canonical storage's children count

#### Scenario: Empty folder shows empty page gracefully
- **WHEN** a share points to a folder with zero children in any storage
- **THEN** the listing MUST render with an empty state
- **AND** the response MUST be HTTP 200 (not 404 or 500)

### Requirement: Subfolder navigation within a share uses cross-storage listing

When a public visitor navigates `GET /s/{token}/folder/{folderId}` (subfolder within a share), the subfolder listing MUST use the same cross-storage logic as the root listing.

#### Scenario: Subfolder under share resolves across storages
- **WHEN** a visitor navigates from a share into a subfolder that is itself a mirror
- **THEN** the subfolder MUST be canonicalized via `FilePhysicalIdentity::canonicalFor()` before listing its contents
- **AND** the listing MUST show cross-storage children

### Requirement: Folder delete via share respects mirror vs canonical

When a share owner deletes the shared folder via `DELETE /s/{token}` or `POST /s/{token}/destroy`, the system MUST distinguish three cases based on whether the target is a mirror or canonical, and the share's permissions.

#### Scenario: Mirror folder delete is metadata-only
- **WHEN** the share's file_id points to a folder with `merged_into_id IS NOT NULL` and the operator triggers delete
- **THEN** the system MUST delete only the mirror row in `files` and the share row
- **AND** the canonical folder and its children MUST remain untouched
- **AND** no files MUST be removed from disk

#### Scenario: Canonical folder with read permission is metadata-only
- **WHEN** the share's file_id points to a folder with `merged_into_id IS NULL` and `share.permissions = 'read'` and the operator triggers delete
- **THEN** the system MUST delete the canonical row and cascade-delete its `files` children rows
- **AND** no files MUST be removed from disk
- **AND** the share row MUST be deleted

#### Scenario: Canonical folder with write or full permission is destructive
- **WHEN** the share's file_id points to a folder with `merged_into_id IS NULL` and `share.permissions IN ('write','full')` and the operator triggers delete
- **THEN** the system MUST `deleteRecursive()` the physical folder on disk
- **AND** cascade-delete all `files` rows under it
- **AND** delete the share row
- **AND** the operation MUST be logged in the audit trail

### Requirement: Folder listing returns exactly one level of children, no grandchildren

When a public visitor opens a folder share and the system computes the visible children of that folder, the listing MUST include only rows whose `parent_id` matches the canonical folder id or the id of any mirror of that folder. The listing MUST NOT include rows whose `parent_id` is a child of the canonical or a child of a mirror (i.e., MUST NOT include grandchildren).

#### Scenario: Canonical folder with sub-folder children returns sub-folders only
- **WHEN** a share points to a canonical folder whose direct children are 121 sub-folders and those sub-folders collectively contain thousands of files
- **THEN** the rendered listing MUST contain exactly 121 items
- **AND** the listing MUST NOT contain any file whose `parent_id` is one of those 121 sub-folders

#### Scenario: Canonical folder with file children is unchanged by the fix
- **WHEN** a share points to a canonical folder whose direct children are 34 files (no sub-folders)
- **THEN** the rendered listing MUST contain exactly 34 items
- **AND** the listing MUST NOT include rows from unrelated parents whose `id` collides with any of the 34 file ids (the previous bug accidentally produced false positives only when child rows were folders)

#### Scenario: Mirror with file children returns only those files
- **WHEN** a share points to a mirror folder that has 97 file children directly under it and the canonical has 0 children
- **THEN** the rendered listing MUST contain exactly 97 items (the mirror's direct children)
- **AND** MUST NOT include rows whose `parent_id` resolves to children of the mirror (none exist in this case)

#### Scenario: Canonical with mirror that has its own file children returns union
- **WHEN** a share points to a canonical folder with 0 direct children and a mirror that has 59 file children of its own
- **THEN** the rendered listing MUST contain exactly 59 items (the mirror's children)
- **AND** the count MUST equal the union of `parent_id = canonical_id` and `parent_id = mirror_id`, nothing more

#### Scenario: Folder with no children anywhere shows empty gracefully
- **WHEN** a share points to a folder that has zero direct children and zero mirrors and zero grandchildren
- **THEN** the response MUST be HTTP 200 with an empty listing
- **AND** the page MUST render the "no hay elementos" empty state
- **AND** the bug fix MUST NOT regress this behavior (the canonical implementation already handled this; the fix preserves it)

#### Scenario: Listing count equals direct children plus mirror children exactly
- **WHEN** a share's folder has N direct children of the canonical and M direct children of each mirror (sum across all mirrors)
- **THEN** after deduplication by name, the listing count MUST equal the size of the deduplicated set
- **AND** the listing MUST NOT exceed N + M (no grandchildren)
- **AND** the listing MUST NOT be less than min(N, M) when names overlap (dedup can reduce but must not zero out)

### Requirement: Folder listing finds equivalent folders by physical path

When a public visitor opens a folder share and the canonical/mirror identity does not cover all folder rows that represent the same physical directory on disk (e.g., a sub-storage folder at the same absolute path that was never linked via `merged_into_id`), the listing MUST discover those equivalent folder rows via `physical_path_normalized` (= `LOWER(RTRIM(base_path_snapshot || '/' || path))`) and include their children.

#### Scenario: Share points to empty parent folder, files live in sub-storage folder at same path
- **WHEN** a share points to folder `7631760` in storage 5 (path `Disco_D/backup/02_Canal_Rcn_bk/18092026`, `merged_into_id IS NULL`, 0 children) and folder `7631759` in storage 34 (path `02_Canal_Rcn_bk/18092026`, `merged_into_id IS NULL`, 35 children) shares the same `physical_path_normalized`
- **THEN** the rendered listing MUST contain exactly 35 items (the 35 files under folder 7631759)
- **AND** the listing MUST NOT be empty (the share MUST show the files even though the share's file_id points to the empty folder)

#### Scenario: Two folders with same physical path but different content merge via dedup
- **WHEN** two folders in different storages share the same `physical_path_normalized` and both contain files with the same name (e.g., one row in storage 5 with one name, one row in storage 34 with the same name)
- **THEN** the listing MUST deduplicate by name and prefer the row from the storage with the longer `base_path` (deeper sub-storage wins)
- **AND** the dedup MUST be deterministic across requests

#### Scenario: Physical-path equivalence does not match folders with different paths
- **WHEN** two folders exist in different storages but have different `physical_path_normalized` values
- **THEN** the listing for either folder MUST NOT include rows from the other folder's children
- **AND** `physical_path_normalized` comparison MUST be case-insensitive (LOWER on both sides) and trim trailing slashes

#### Scenario: Physical-path equivalence falls back gracefully when base_path_snapshot is missing
- **WHEN** a folder has `base_path_snapshot IS NULL` (the snapshot was never populated, e.g., rows created before the FileObserver was deployed)
- **THEN** the system MUST still return the canonical + mirror children correctly
- **AND** MUST NOT crash or 500
- **AND** MUST log a warning so the operator can backfill `base_path_snapshot` via `files:resync-base-path-snapshots`