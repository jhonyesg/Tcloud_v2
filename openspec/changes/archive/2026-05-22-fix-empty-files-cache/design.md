## Context

The "Mis Archivos" file manager uses a 3-tier data loading strategy: Redis cache → PostgreSQL DB → live disk scan (auto-scan). When a user first visits a storage folder, if the DB has no records (cron hasn't synced yet), the backend triggers `syncFolder()` to scan the disk and populate the DB.

The problem: when `syncFolder()` returns empty (NFS timeout, `realpath()` failure, `scandir()` error), the empty result falls through to the caching layer and gets stored in Redis for 60s (root) to 86400s (old subfolders). This poisons the cache — all subsequent requests within that TTL receive the cached empty response.

The "Actualizar" button works because it sends `&sync=1`, which takes a separate code path (line 114 of FileController) that bypasses the cache entirely.

Additionally, the cron `storage:sync --all` command never invalidates the root folder's cache because `invalidateFolderCache()` is only called when `$parentId !== null` (StorageSyncService line 96).

## Goals / Non-Goals

**Goals:**
- Prevent empty auto-scan results from being cached in Redis
- Ensure root folder cache is properly invalidated by the cron sync
- Prevent the "No hay archivos" empty state from flashing during normal loading latency
- Maintain backward compatibility — no API contract changes

**Non-Goals:**
- Redesigning the entire caching strategy (TTLs, cache keys, etc.)
- Adding distributed locking between auto-scan and cron sync
- Changing the `sync=1` bypass behavior
- Modifying the cron schedule frequency

## Decisions

### Decision 1: Skip cache on empty auto-scan results

**Choice**: When the auto-scan (lines 156–166 of FileController) returns zero files, skip the `Cache::put()` at line 186.

**Rationale**: The auto-scan is a fallback for when the DB is empty. If the scan also returns empty, it's likely a transient failure (NFS, permissions, etc.). Caching this result poisons the cache for the entire TTL window. By not caching, the next request will re-query the DB (which may now have been populated by a retry or the cron).

**Alternative considered**: Use a very short TTL (5s) for empty auto-scan results. Rejected because it adds complexity (tracking whether the result came from auto-scan) and still allows a small window of cached emptiness.

**Implementation**: Add a boolean flag `$isAutoScan = false` before the auto-scan block, set it to `true` inside, then wrap `Cache::put()` with `if (!($isAutoScan && empty($responseData['files'])))`.

### Decision 2: Expand cache invalidation to root folder

**Choice**: Move `invalidateFolderCache()` call in `StorageSyncService::syncFolder()` outside the `if ($parentId !== null)` block, so it runs for both root and subfolder syncs.

**Rationale**: Currently, when cron syncs the root folder and creates/deletes files, the root folder cache is never invalidated. The TTL is only 60s so it's less critical, but during that window stale data is served. More importantly, the auto-scan path (line 119 of FileController) already calls `invalidateFolderCache()` after sync — the cron should be consistent.

**Implementation**: Restructure the conditional at lines 96–104 of StorageSyncService.php:
```php
if ($parentFolder) {
    $dirMtime = Carbon::createFromTimestamp(filemtime($realPath));
    $parentFolder->update(['file_modified_at' => $dirMtime]);
}
if ($created > 0 || $deleted > 0) {
    $this->invalidateFolderCache($storage->id, $parentId);
}
```

### Decision 3: Frontend delay before showing empty state

**Choice**: Add a `_emptyStateTimer` that delays showing "No hay archivos" by ~1500ms after loading completes.

**Rationale**: Even with the backend fix, there's a normal latency window (auto-scan NFS time) where `files.length === 0 && !isLoadingFiles` is true. A short delay prevents the flash without affecting perceived performance — users already expect a loading moment when entering a folder.

**Alternative considered**: Show a different loading indicator during auto-scan. Rejected because the backend doesn't distinguish auto-scan from normal loading in the response.

**Implementation in `index.blade.php`**:
- Add state: `_emptyStateTimer: null`, `showEmptyState: false`
- In `loadFiles()`: clear any existing timer, set `showEmptyState = false`
- When `loadFiles()` completes with empty files: start a 1500ms timer that sets `showEmptyState = true`
- When `loadFiles()` completes with files: clear timer, `showEmptyState = false`
- Change the empty state `x-show` from `files.length === 0 && !isLoadingFiles` to `files.length === 0 && !isLoadingFiles && showEmptyState`

## Risks / Trade-offs

- **[Risk] Cache miss storm on first visit**: Without caching empty auto-scan results, rapid successive requests to the same empty folder will each trigger a DB query + auto-scan. **Mitigation**: The `AbortController` on the frontend already cancels stale requests. In practice, users don't hammer the same folder rapidly. The 60s TTL on non-auto-scan results still applies.

- **[Risk] 1.5s delay feels sluggish for genuinely empty folders**: Users with truly empty folders wait 1.5s before seeing the empty state. **Mitigation**: Show the skeleton loader during this delay (already implemented — skeleton shows when `isLoadingFiles && files.length === 0`). The skeleton disappears when loading ends, then the empty state appears after 1.5s. Total perceived wait is acceptable.

- **[Risk] Root cache invalidation causes more cache misses**: Invalidating root folder cache more frequently means more cache misses. **Mitigation**: Root TTL is already short (60s). The invalidation only happens when files are actually created/deleted, which is infrequent at the root level.
