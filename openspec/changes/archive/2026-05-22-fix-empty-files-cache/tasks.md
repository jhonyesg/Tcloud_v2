## 1. Backend — Cache Fix (FileController)

- [x] 1.1 In `app/app/Http/Controllers/FileController.php`, add a `$isAutoScan = false` flag before the auto-scan block (line ~156), set it to `true` inside the block, then wrap `Cache::put()` (line ~186) with a condition that skips caching when `$isAutoScan && empty($responseData['files'])`

## 2. Backend — Root Folder Cache Invalidation (StorageSyncService)

- [x] 2.1 In `app/app/Services/StorageSyncService.php`, restructure lines 96–104: move the `invalidateFolderCache()` call outside the `if ($parentId !== null)` block so it runs for both root and subfolder syncs when files are created or deleted

## 3. Frontend — Empty State Delay (files/index.blade.php)

- [x] 3.1 In `app/resources/views/files/index.blade.php`, add Alpine.js state properties `_emptyStateTimer: null` and `showEmptyState: false` to the `fileManager` data object
- [x] 3.2 In the `loadFiles()` method, clear any existing `_emptyStateTimer` and set `showEmptyState = false` at the start of the request
- [x] 3.3 In the `loadFiles()` success handler, when `serverData.length === 0`, start a 1500ms timer that sets `showEmptyState = true`; when `serverData.length > 0`, clear the timer and set `showEmptyState = false`
- [x] 3.4 In the `loadFiles()` error handler, clear the timer and set `showEmptyState = false`
- [x] 3.5 Update the empty state `x-show` condition (line ~2692) from `files.length === 0 && !isLoadingFiles` to `files.length === 0 && !isLoadingFiles && showEmptyState`
- [x] 3.6 In `navigateToFolder()`, clear `_emptyStateTimer` and set `showEmptyState = false` when resetting files
- [x] 3.7 In `refreshFiles()`, ensure the delay timer is cleared so "Actualizar" shows the skeleton immediately

## 4. Verification

- [x] 4.1 Test: navigate to a storage folder — skeleton shows during load, files appear (or empty state after 1.5s if truly empty)
- [x] 4.2 Test: click "Actualizar" on empty folder — skeleton shows immediately, no flash of empty state
- [x] 4.3 Test: rapid navigation between folders — no empty state flash from previous folder
- [x] 4.4 Test: verify Redis cache does NOT contain empty auto-scan results (check with `redis-cli GET folder_listing:*`)
- [x] 4.5 Test: run `php artisan storage:sync --all` and verify root folder cache is invalidated when files change
