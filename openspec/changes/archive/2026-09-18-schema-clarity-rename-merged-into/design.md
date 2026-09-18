## Context

See proposal.md for motivation. The current state is two `merged_into_id` columns with overlapping names but disjoint semantics, plus a `type` vs `kind` redundancy on `storage_providers`. This design documents the technical approach for renaming columns atomically while preserving data and FK semantics. No frontend view or Alpine state changes; this is a backend + DB schema change.

## Goals / Non-Goals

**Goals:**
- Single transactional migration per column rename; preserves data, FK constraints, and partial indexes.
- Call sites updated atomically in the same commit as the migration; reduces drift window to zero.
- Reverse `down()` for rollback without data loss.
- Audit log (`file_mirror_audit_log`) **unchanged**: the `action` enum values describe the conceptual operation, not the column name.

**Non-Goals:**
- No new index, no new constraint policy, no change in CASCADE/SET NULL behavior.
- No removal of `storage_providers.type` in this PR (drop is deferred to PR 2 once `kind` is verified as sole discriminator).
- No new endpoints or model methods beyond renaming the existing ones.

## Decisions

### Decision 1: Single-migration vs phased migration

**Choice**: One atomic migration per column rename (two migrations total: one for `files`, one for `storage_providers`).

**Rationale**: The schema rename is non-breaking for app code (we update call sites in the same commit). Phased would require either: (a) keeping both columns with a sync trigger — adds complexity and a deprecation window; (b) renaming one and immediately the other — no benefit over atomic. A single ALTER TABLE inside a PostgreSQL transaction is metadata-only and effectively instant on tables with ~700k rows because PG doesn't rewrite tuples, only updates catalog.

**Alternatives considered**:
- *Phase with dual columns*: rejected — adds 2 weeks of accidental complexity for no operational benefit since no external consumer reads these columns.
- *Rename only in code, keep DB names*: rejected — the goal is clarity at the BD level for future audits.

### Decision 2: Constraint and index renaming

**Choice**: Rename the FK constraint and the partial index alongside the column, using PG's `ALTER CONSTRAINT ... RENAME` and `ALTER INDEX ... RENAME`.

**Rationale**: PG allows renaming these in catalog without rewriting data. Keeping the old constraint name would leave the schema inconsistent (column is `canonical_folder_id` but FK still says `files_merged_into_id_fkey`), which defeats the clarity goal of this change.

**Alternative considered**: *Leave the constraint name unchanged* — rejected because the inconsistency becomes a new form of confusion.

### Decision 3: Renaming of model getters (`isMirror` → `isFolderMirror`)

**Choice**: Rename `File::isMirror()` → `File::isFolderMirror()` and `File::canonical()` → `File::canonicalFolder()`. Same for `StorageProvider::isMerged()` → `isDuplicate()`.

**Rationale**: The original names collided with verbal usage. `isMirror()` was unclear about what mirrors (a folder row? a storage?). After renaming the column, the method names need a parallel update to match.

**Alternative considered**: *Keep methods, only rename column* — rejected because the new column name (`canonical_folder_id`) is about folders specifically; the helpers should reflect that.

### Decision 4: No db_backward_compatibility window

**Choice**: App code references only the new column name. No "if column exists, use old else use new" logic.

**Rationale**: There's no external reader of these columns (no API exposes them; no dashboards depend on the literal name). Adding a fallback logic would lengthen the codebase and create double-write paths.

**Mitigation**: The change ships as ONE atomic migration + ONE atomic code commit. Rollback via `migrate:rollback --step=N` + `git revert` on the code commit.

## Risks / Trade-offs

- **[Risk] Blast radius across ~35 files in the same commit** → Mitigation: each file is mechanical (rename-only). The proposed `harness_merged_into_rename_compat.php` runs after the migration and validates that the column rename succeeded without breaking FKs, indexes, or values.
- **[Risk] Indexer / search / blade templates referencing the old name** → Mitigation: add a `rg "merged_into_id"` step to the migration task list; the result must be 0 matches in `app/`.
- **[Risk] Tests/harnesses hardcode the column name in raw SQL** → Mitigation: target grep covers both `app/tests/` and the harness files in the rename task; verified during implementation.
- **[Trade-off] Lose the ability to read `merged_into_id` directly during a partial migration** → Acceptable: migration runs in milliseconds (catalog only); app code is updated atomically. No production window where both names coexist.

## Migration Plan

**Deploy order**:
1. Tag the working tree at the pre-rename commit (git tag `pre-merged-into-rename`).
2. Apply the migration: `php artisan migrate` — produces `ALTER TABLE files RENAME COLUMN merged_into_id TO canonical_folder_id;` and same for `storage_providers.merged_into_id` → `duplicate_of_storage_id`. Both wrapped in `BEGIN; ... COMMIT;`.
3. Deploy the code change: `git pull` — updates models, services, controllers, commands, harnesses.
4. Run harness: `php app/tests/harness_merged_into_rename_compat.php`. Must show exit 0.
5. Smoke test: open `/admin/storages`, run `storages:detect-duplicate-paths --dry-run`, run `files:repair-folder-mirrors --dry-run`.

**Rollback**:
- `php artisan migrate:rollback --step=2` renames the columns back.
- `git revert <commit-hash>` reverts the code changes.
- The harness `harness_merged_into_rename_compat.php` must be runnable against both states (it asserts the old name resolves to the same rows when reverted).

## Open Questions

- **¿Migrar también `storage_providers.type` en este PR o esperar?** Decidido en proposal: se depreca con `@deprecated` en el modelo pero no se elimina. Razón: hay 1 fila con `type='s3' | kind='local'` que requiere inspección manual antes de remover `type`. Costo: 1 migration adicional pequeña en PR 2.
- **¿Helpers `File::canonicalFolder()` vs renombrarlo a `File::canonicalRowOf()`?** Decidido `canonicalFolder()` por brevedad y porque el dominio del row es folder (no cualquier archivo).
