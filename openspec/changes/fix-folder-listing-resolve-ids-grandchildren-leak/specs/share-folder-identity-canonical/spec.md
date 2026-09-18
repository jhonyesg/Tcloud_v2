## ADDED Requirements

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
