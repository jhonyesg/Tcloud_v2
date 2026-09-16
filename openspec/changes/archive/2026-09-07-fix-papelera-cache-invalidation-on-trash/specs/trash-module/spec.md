## ADDED Requirements

### Requirement: Cache invalidation covers root listing after soft-trash

The system MUST invalidate the `FileController@index` cache for both the file's original parent folder AND for the storage root listing whenever a soft-trash moves the file's `parent_id` from a non-null value to NULL. Without the root-listing invalidation, the cached `whereNull('parent_id')` payload returns the just-trashed row for up to 60 seconds after the delete action, surfacing ghost items in `/files` until the TTL expires.

#### Scenario: Trashed item vanishes from root listing immediately
- **WHEN** a user soft-trashes a file whose original parent is a non-root folder (so the post-trash `parent_id` becomes NULL), and another user has loaded the storage's root listing cached
- **THEN** the next request to `/files` for that storage root MUST NOT return the trashed file in the listing payload

#### Scenario: File already at root before trash still works
- **WHEN** a user soft-trashes a file that was already at the storage root (`original_parent_id` is NULL and post-trash `parent_id` is NULL)
- **THEN** the existing single invalidation call (already in `FileController@destroy`) covers the root case correctly; no regression
