## Why

The plugin-based viewer system in `files/index.blade.php` has 6 cascading failure layers (DB lookup → dynamic script loading → nginx caching → Alpine binding conflicts → window function lookup → innerHTML replacement) making it unreliable. An inline viewer using native HTML elements and Alpine `x-show` already works correctly in `shares/public.blade.php` and can be directly adapted.

## What Changes

- Remove `toolModal`, `loadPluginResources`, `initializePlugin`, `launchTool`, and `openFileViewer` from `fileManager` Alpine component
- Remove the tool modal HTML block (`#tool-modal`) from `files/index.blade.php`
- Add an inline viewer modal with `x-show` per mime type: `<video>`, `<audio>`, `<img>`, `<iframe>` for PDF
- Add `openViewer(file)` / `closeViewer()` methods that set `currentViewerFile` and manage `<video>`/`<audio>` src via `$refs`
- Add mime-type helper functions: `isVideo()`, `isAudio()`, `isImage()`, `isPdf()`, `isText()`
- All media served from `/media/{file.id}/preview` (already supports image, PDF, audio, video)
- Keep `file_tool_plugins` table and admin UI untouched
- Keep file-click handlers but point them at the new `openViewer()` instead of plugin system

## Capabilities

### New Capabilities
- `inline-file-viewer`: Modal viewer for files in "Mis Archivos" using native HTML elements and Alpine x-show, supporting video, audio, image, PDF, and text mime types without any plugin loading.

### Modified Capabilities

## Impact

- Only `app/resources/views/files/index.blade.php` is modified
- No backend changes required — `/media/{file}/preview` already handles all needed types
- No database changes — `file_tool_plugins` table stays intact
- Plugin JS files in `public/plugins/` remain on disk but are no longer loaded in the files view
