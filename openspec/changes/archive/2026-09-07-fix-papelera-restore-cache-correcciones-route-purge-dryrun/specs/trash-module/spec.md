## MODIFIED Requirements

### Requirement: Restore semantics

The system MUST let the original owner (or admin) restore a trashed item. Restoration MUST attempt to place the item back under its `original_parent_id`; if that parent is missing or also trashed, the item MUST be restored to the root of its storage provider. If a name collision exists at the destination, the system MUST suffix the restored name with `-restored-<unix_timestamp>`. Additionally, the restore endpoint MUST invalidate the cached listing of the destination folder so the restored file becomes visible in the file browser without waiting for the cache TTL to expire.

#### Scenario: Restore with original parent still present
- **WHEN** user restores a trashed file whose `original_parent_id` points to a non-trashed folder
- **THEN** the file's `parent_id` is set back to `original_parent_id`, `is_trashed=false`, `deleted_at=NULL`, `original_parent_id=NULL`
- **AND** `StorageSyncService::invalidateFolderCache(storage_id, parent_id)` is called so the destination listing cache is bumped

#### Scenario: Restore with original parent missing
- **WHEN** user restores a trashed file whose `original_parent_id` no longer resolves to an existing folder
- **THEN** the file is placed at the root of its storage provider (`parent_id=NULL` but `is_trashed=false`)
- **AND** the root listing cache of that storage is invalidated

#### Scenario: Restore with name collision
- **WHEN** user restores a file whose destination already has a sibling with the same name
- **THEN** the restored file is renamed with the suffix `-restored-<unix_timestamp>` before insertion
- **AND** the destination folder cache is invalidated after the rename

#### Scenario: Restore with collision uses timestamp-suffixed name (no orphan cache)
- **WHEN** the restore renames the file due to name collision and the original (pre-restore) cache key pointed to the destination
- **THEN** the cache for that destination folder is regenerated on the next browser load (the old cache is invalidated, not just orphaned)
