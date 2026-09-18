## ADDED Requirements

### Requirement: Columna renamed para identidad de folder

The system SHALL rename `files.merged_into_id` to `files.canonical_folder_id` so that the column name unambiguously expresses its domain (a folder row that is a mirror pointing to a canonical row in the same table).

#### Scenario: Columna renommée en BD
- **WHEN** se ejecuta la migration de rename
- **THEN** la columna SHALL llamarse `canonical_folder_id`
- **AND** SHALL conservar el FK constraint `files_merged_into_id_fkey` (renombrado a `files_canonical_folder_id_fkey`)
- **AND** SHALL conservar el partial index `files_merged_into_id_idx` (renombrado a `files_canonical_folder_id_idx`)
- **AND** SHALL conservar `ON DELETE SET NULL` semantics

#### Scenario: Helpers renombrados en modelo
- **WHEN** el código consulta la identidad de un folder row
- **THEN** SHALL usar `File::canonicalFolderId()` y `File::isFolderMirror()` en lugar de los getters viejos
- **AND** SHALL NO existir el método `File::isMirror()` (eliminado; reemplazado por `isFolderMirror()`)
- **AND** SHALL NO existir `File::canonical()` (renombrado a `File::canonicalFolder()` para desambiguar de otros usos de "canonical")

#### Scenario: Audit log action enum sin cambios
- **WHEN** el trigger `file_mirror_audit_log_append_only_trigger` rechaza UPDATE/DELETE
- **THEN** SHALL seguir rechazando filas del log
- **AND** SHALL NO requerir migración del audit log (los `action` values `link_mirror`, `unlink_mirror`, etc. describen conceptualmente la operación y no cambian)

#### Scenario: Queries SQL actualizadas
- **WHEN** cualquier consulta referencia `merged_into_id` en `files`
- **THEN** SHALL usar `canonical_folder_id`
- **AND** SHALL NO quedar referencias viejas (verificado por `rg "files.*merged_into_id" app/` retornando 0 matches)

## MODIFIED Requirements

### Requirement: Share creation canonicalizes folder file_id

When a user creates a share via `POST /shares` with a `file_id` pointing to a folder that is a mirror (i.e., has `canonical_folder_id IS NOT NULL`), the system MUST canonicalize the `file_id` to the corresponding canonical folder before persisting the share.

#### Scenario: Mirror folder is auto-redirected to canonical
- **WHEN** a `POST /shares` request includes `file_id = 7244491` (a mirror) whose canonical is `7244379`
- **THEN** the persisted share MUST have `file_id = 7244379`
- **AND** the response MUST include the canonical file_id, not the mirror

#### Scenario: Canonical folder passes through unchanged
- **WHEN** a `POST /shares` request includes `file_id` pointing to a folder with `canonical_folder_id IS NULL`
- **THEN** the share MUST persist with that exact file_id

#### Scenario: File (non-folder) shares are not canonicalized
- **WHEN** a `POST /shares` request includes `file_id` pointing to a file (not folder)
- **THEN** the share MUST persist with the original file_id regardless of any canonical_folder_id on the row

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
- **WHEN** the share's file_id points to a folder with `canonical_folder_id IS NOT NULL` and the operator triggers delete
- **THEN** the system MUST delete only the mirror row in `files` and the share row
- **AND** the canonical folder and its children MUST remain untouched
- **AND** no files MUST be removed from disk

#### Scenario: Canonical folder with read permission is metadata-only
- **WHEN** the share's file_id points to a folder with `canonical_folder_id IS NULL` and `share.permissions = 'read'` and the operator triggers delete
- **THEN** the system MUST delete the canonical row and cascade-delete its `files` children rows
- **AND** no files MUST be removed from disk
- **AND** the share row MUST be deleted

#### Scenario: Canonical folder with write or full permission is destructive
- **WHEN** the share's file_id points to a folder with `canonical_folder_id IS NULL` and `share.permissions IN ('write','full')` and the operator triggers delete
- **THEN** the system MUST `deleteRecursive()` the physical folder on disk
- **AND** cascade-delete all `files` rows under it
- **AND** delete the share row
- **AND** the operation MUST be logged in the audit trail
