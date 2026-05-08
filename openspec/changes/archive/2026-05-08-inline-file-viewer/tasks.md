## 1. Remove Plugin System from fileManager Alpine Component

- [x] 1.1 Remove Alpine state variables: `toolModal`, `availableTools`, and all plugin-related init code from `fileManager` in `files/index.blade.php`
- [x] 1.2 Remove methods: `loadAvailableTools`, `loadPluginResources`, `initializePlugin`, `launchTool`, `openFileViewer`, `closeToolModal`
- [x] 1.3 Remove the `init()` call to `loadAvailableTools()` if present

## 2. Add Inline Viewer State and Methods

- [x] 2.1 Add state variables to `fileManager`: `viewerOpen: false`, `currentViewerFile: null`
- [x] 2.2 Add mime-type helper methods: `isVideo(mime)`, `isAudio(mime)`, `isImage(mime)`, `isPdf(mime)`
- [x] 2.3 Add `getViewerUrl(file)` returning `/media/${file.id}/preview`
- [x] 2.4 Add `openViewer(file)` method: sets `currentViewerFile`, sets `viewerOpen = true`, then sets `$refs.videoplayer.src` or `$refs.audioplayer.src` via `$nextTick` for video/audio
- [x] 2.5 Add `closeViewer()` method: pauses and clears src on `$refs.videoplayer` and `$refs.audioplayer`, sets `viewerOpen = false`, clears `currentViewerFile`

## 3. Replace Tool Modal HTML with Inline Viewer Modal

- [x] 3.1 Remove the entire `#tool-modal` / `toolModal` HTML block from `files/index.blade.php`
- [x] 3.2 Add inline viewer modal HTML with `x-show="viewerOpen"` containing conditional sections per mime type: `<video x-ref="videoplayer">`, `<audio x-ref="audioplayer">`, `<img>`, `<iframe>` for PDF
- [x] 3.3 Add close button that calls `closeViewer()`
- [x] 3.4 Add file name and download link (`/files/{id}/download`) in the viewer header

## 4. Update File Click Handlers

- [x] 4.1 Update grid-view file click handler to call `openViewer(file)` instead of `openFileViewer(file)` or `launchTool`
- [x] 4.2 Update list-view file click handler to call `openViewer(file)`

## 5. Verification

- [ ] 5.1 Test clicking a video file — player opens and plays
- [ ] 5.2 Test clicking an audio file — player opens and plays
- [ ] 5.3 Test clicking an image — image renders
- [ ] 5.4 Test clicking a PDF — iframe renders PDF
- [ ] 5.5 Test closing modal — media stops, modal hides

