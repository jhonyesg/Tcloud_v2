## Context

`files/index.blade.php` currently loads file viewers via a plugin system: DB lookup → dynamic `<script>` injection → `window.<slug>_init(options)` call → innerHTML replacement inside an Alpine-managed div. This has caused irreproducible failures across 6 independent layers. `shares/public.blade.php` already has a working inline viewer using the identical media endpoint (`/media/{file}/preview`) and native HTML elements with Alpine `x-show`.

## Goals / Non-Goals

**Goals:**
- Replace the plugin-loading viewer in `files/index.blade.php` with an inline viewer that mirrors `shares/public.blade.php`
- Support video, audio, image, PDF, and text mime types without any plugin loading
- Eliminate all plugin-loading code paths from the files view

**Non-Goals:**
- Modifying `shares/public.blade.php` (already works, don't touch it)
- Removing the `file_tool_plugins` DB table, seeder, admin UI, or plugin JS files
- Adding advanced features (subtitles, playlists, seek bar customization) — deferred

## Decisions

**Decision 1: Adopt shares/public.blade.php pattern verbatim**
Use the exact same Alpine data structure and HTML pattern from the working public share view. This avoids re-inventing something already proven. Adaptation needed: replace `/s/${token}/media/${file.id}/preview` with `/media/${file.id}/preview` (authenticated route).

**Decision 2: Single `currentViewerFile` state variable**
One variable drives all `x-show` conditions (`isVideo(currentViewerFile.mime_type)`, etc.). `openViewer(file)` sets it; `closeViewer()` nulls it and stops media playback via `$refs`.

**Decision 3: Video/audio src set via JS, not `:src` binding**
Alpine `:src` bindings can cause premature network requests. Set `this.$refs.videoplayer.src = url` on open, clear it on close — same approach used in the public share view.

**Decision 4: Keep all plugin system code intact in backend**
Admin UI, DB table, seeder, and plugin JS files are not modified. The viewer frontend simply stops calling the plugin system. The plugin infrastructure remains for future advanced-viewer work.

**Decision 5: Remove `loadAvailableTools` and tool-related Alpine state from fileManager**
`availableTools`, `toolModal`, `loadPluginResources`, `initializePlugin`, `launchTool` are removed. The "Herramientas" button in the toolbar is kept in the HTML but pointing to the admin tools route — it's UI unrelated to viewing.

## Risks / Trade-offs

- **No text viewer yet** → For text files, fall back to a download prompt. Text viewing can be added later without changing the architecture.
- **iframe PDF depends on browser PDF plugin** → Same limitation as the current system and shares view. Acceptable for now.
- **Removing plugin state from Alpine** → If any other part of the page reads `toolModal` or `availableTools`, it will break. Check: these are only used within the tool modal block being removed.

## Migration Plan

1. Remove Alpine state: `toolModal`, `availableTools`, plugin-related methods
2. Add Alpine state: `currentViewerFile`, `viewerOpen`, mime-type helpers, `openViewer`/`closeViewer`
3. Replace tool modal HTML with inline viewer modal HTML
4. Update file click handlers to call `openViewer(file)` instead of `openFileViewer(file)`
5. No DB migrations, no route changes, no container rebuilds required
