## Why

When users open "Mis Archivos" or navigate into any storage folder, the UI frequently shows "No hay archivos / No hay archivos en este storage" even though files exist on disk. Clicking "Actualizar" (refresh) immediately shows the files. This is reported constantly and degrades trust in the file manager.

The root cause: when the auto-scan (triggered on first visit when DB is empty) fails due to NFS latency or transient filesystem errors, the **empty result gets cached in Redis for 60–86400 seconds**. All subsequent requests within that window receive the cached empty response. The "Actualizar" button works because it sends `sync=1` which bypasses the cache entirely.

## What Changes

- **Backend — Stop caching empty auto-scan results**: In `FileController::index()`, skip `Cache::put()` when the result came from an auto-scan that returned zero files. This prevents a transient NFS failure from poisoning the cache.
- **Backend — Fix root folder cache invalidation**: In `StorageSyncService::syncFolder()`, move `invalidateFolderCache()` outside the `if ($parentId !== null)` block so the cron sync also invalidates root folder cache when files are created/deleted.
- **Frontend — Delay empty state rendering**: In `files/index.blade.php`, add a short delay (~1.5s) before showing the "No hay archivos" empty state to avoid flashing it during normal loading latency.

## Capabilities

### New Capabilities

- `empty-state-loading-ux`: Controls how the file manager renders loading vs empty states, including delay logic to prevent premature empty state display.

### Modified Capabilities

<!-- None — the backend cache fixes are implementation-level changes that don't alter the API contract or spec-level behavior -->

## Impact

- **Files affected**:
  - `app/app/Http/Controllers/FileController.php` — lines 156–188 (auto-scan + cache logic)
  - `app/app/Services/StorageSyncService.php` — lines 96–104 (cache invalidation scope)
  - `app/resources/views/files/index.blade.php` — lines 2692–2707 (empty state rendering) + `loadFiles()` method
- **APIs**: `GET /files` response behavior changes (no more cached empty results from auto-scan)
- **Cache**: Redis cache behavior changes — empty auto-scan results no longer persisted; root folder cache now properly invalidated by cron
- **No migration required**
- **No breaking changes** — all changes are backward-compatible behavioral fixes
