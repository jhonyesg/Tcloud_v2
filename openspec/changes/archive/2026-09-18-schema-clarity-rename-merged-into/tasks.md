## 1. Pre-implementation blast radius analysis

- [ ] 1.1 Run `rg "files\.merged_into_id|files->merged_into_id|->merged_into_id|files.*merged_into_id" app/ tests/ openspec/` and record the file:line count of matches (≈22 expected from prior audit).
- [ ] 1.2 Run `rg "storage_providers.*merged_into_id|storage->merged_into_id|->merged_into_id on storage" app/ tests/ openspec/` and record the file:line count (≈19 expected).
- [ ] 1.3 Run `rg "merged_into_id" app/database/migrations/` to confirm no migration references the name in a way that would conflict with renaming.
- [ ] 1.4 Tag the working tree `pre-merged-into-rename` for rollback reference.

## 2. Database migrations

- [ ] 2.1 Create `app/database/migrations/2026_09_XX_rename_merged_into_to_canonical_folder_id.php` with `up()`:
  - `BEGIN;`
  - `ALTER TABLE files RENAME COLUMN merged_into_id TO canonical_folder_id;`
  - `ALTER TABLE files RENAME CONSTRAINT files_merged_into_id_fkey TO files_canonical_folder_id_fkey;`
  - `ALTER INDEX files_merged_into_id_idx RENAME TO files_canonical_folder_id_idx;`
  - `COMMIT;`
  - And `down()` that reverses all three.
- [ ] 2.2 Create `app/database/migrations/2026_09_XX_rename_storage_providers_merged_into_to_duplicate_of_storage_id.php` with `up()`:
  - `BEGIN;`
  - `ALTER TABLE storage_providers RENAME COLUMN merged_into_id TO duplicate_of_storage_id;`
  - `ALTER TABLE storage_providers RENAME CONSTRAINT storage_providers_merged_into_id_fkey TO storage_providers_duplicate_of_storage_id_fkey;`
  - `ALTER INDEX storage_providers_merged_into_id_idx RENAME TO storage_providers_duplicate_of_storage_id_idx;`
  - `COMMIT;`
  - And `down()` reverse.
- [ ] 2.3 Run `php artisan migrate --pretend` to confirm both migrations generate the expected SQL.
- [ ] 2.4 Run `php artisan migrate` against the dev DB; verify with `\d files` and `\d storage_providers` that the new column names exist.
- [ ] 2.5 Run `php artisan migrate:rollback --step=2`; verify the columns are renamed back.
- [ ] 2.6 Re-run `php artisan migrate` to land in the renamed state.

## 3. Model updates

- [ ] 3.1 Update `app/app/Models/File.php`:
  - Replace `merged_into_id` in `$fillable` with `canonical_folder_id`.
  - Rename method `isMirror()` → `isFolderMirror()`. Update doc-block to clarify scope.
  - Rename method `canonical()` → `canonicalFolder()`. Add deprecation alias `canonical()` that proxies to `canonicalFolder()` for one release cycle (then remove).
  - Update physicalIdentity getter that reads `$this->merged_into_id` to read `$this->canonical_folder_id`.
- [ ] 3.2 Update `app/app/Models/StorageProvider.php`:
  - Replace `merged_into_id` in `$fillable` with `duplicate_of_storage_id`.
  - Rename method `isMerged()` → `isDuplicate()`.
  - Update `mergedInto()` relation to read the new column.
- [ ] 3.3 Add `@property` PHPDoc to both models documenting the renamed columns so static analysis tools see them.

## 4. Service and command updates

- [ ] 4.1 Update `app/app/Services/FilePhysicalIdentity.php`:
  - Replace 6 references to `$file->merged_into_id` and `$mirror->merged_into_id` with the new name.
  - Update method names: `link()` semantics unchanged, just internal column references.
- [ ] 4.2 Update `app/app/Services/FolderListingService.php`:
  - Replace `$folder->merged_into_id` with `$folder->canonical_folder_id` in `resolveFolderIds()`.
- [ ] 4.3 Update `app/app/Console/Commands/MergeDuplicatesCommand.php`:
  - Replace 4 references to `merged_into_id` with `duplicate_of_storage_id`.
  - Update error messages to use the new column name.
- [ ] 4.4 Update `app/app/Console/Commands/RepairFolderMirrorsCommand.php`:
  - Replace `$mirror->merged_into_id` with `$mirror->canonical_folder_id` in `runForStorage()`.
- [ ] 4.5 Update `app/app/Console/Commands/ShowCanonicalizationImpactCommand.php`:
  - Replace SQL `whereNotNull('files.merged_into_id')` with `whereNotNull('files.canonical_folder_id')`.
- [ ] 4.6 Update `app/app/Http/Controllers/PublicShareController.php`:
  - Replace `$file->merged_into_id` with `$file->canonical_folder_id` in the `destroy()` log payload.
- [ ] 4.7 Update `app/app/Http/Controllers/StorageProviderController.php`:
  - Replace `$storage->merged_into_id` with `$storage->duplicate_of_storage_id` in the `update()` block.
- [ ] 4.8 Search for any blade files that mention `merged_into_id` (unlikely but verify): `rg "merged_into_id" app/resources/views/` should return 0.

## 5. Harness and regression coverage

- [ ] 5.1 Create `app/tests/harness_merged_into_rename_compat.php`:
  - Assert `\d files` shows `canonical_folder_id` column.
  - Assert `\d storage_providers` shows `duplicate_of_storage_id` column.
  - Assert FK constraints renamed.
  - Assert indexes renamed.
  - Assert `WHERE canonical_folder_id IS NOT NULL` returns the same row count as the pre-rename `WHERE merged_into_id IS NOT NULL` (run from a fixture seeded by the harness itself).
  - Assert same for `storage_providers.duplicate_of_storage_id`.
  - Assert that `File::isFolderMirror()` returns true for the seeded mirror row and false for the canonical.
  - Assert that `StorageProvider::isDuplicate()` returns true for the seeded duplicate row.
- [ ] 5.2 Update existing harness `app/tests/harness_files_folder_mirror.php`:
  - Replace `whereNotNull('merged_into_id')` queries with the new column name.
  - Verify all 15 scenarios still pass.
- [ ] 5.3 Run `php app/tests/harness_files_folder_mirror.php`. Exit 0 required.
- [ ] 5.4 Run `php app/tests/harness_storage_sync_is_file_linked.php`. Exit 0 required (this one references `merged_into_id` via SQL).

## 6. Validation and lint

- [ ] 6.1 Run `rg "merged_into_id" app/ tests/ openspec/`. Expected: 0 matches (or only matches inside `openspec/changes/archive/` and opsspec docs explaining the historical name).
- [ ] 6.2 Run `php -l` on every modified PHP file; expected 0 syntax errors.
- [ ] 6.3 Smoke test in dev:
  - `php artisan tinker` → `StorageProvider::find(132)->duplicate_of_storage_id` returns the expected ID.
  - `php artisan tinker` → `File::find(7244379)->canonical_folder_id` returns NULL (it's the canonical).
- [ ] 6.4 Open the admin storages page in the dev browser: should render identically (no UI changes).
- [ ] 6.5 Open a folder share in `/s/{token}` for a known mirror share (e.g. token from the spec scenario); confirm the listing still resolves to canonical.

## 7. Documentation update

- [ ] 7.1 Add a section to `app/AGENTS.md` under "Convenciones de código" titled "Schema clarity policy":
  - Quote the new capability `schema-clarity-rename-policy`.
  - State: "Two different tables using the same column name for different concepts is clarity debt. Resolve via rename, not via documentation."
- [ ] 7.2 Update the doc-blocks in `File` and `StorageProvider` to point to the AGENTS.md section (so future readers find it).

## 8. Archive

- [ ] 8.1 Run `openspec archive schema-clarity-rename-merged-into --yes` once all tasks pass and the change is merged.
- [ ] 8.2 Confirm the delta specs were merged into `openspec/specs/storage-physical-identity/spec.md` and `openspec/specs/share-folder-identity-canonical/spec.md`, and the new capability `schema-clarity-rename-policy` exists at `openspec/specs/schema-clarity-rename-policy/spec.md`.
