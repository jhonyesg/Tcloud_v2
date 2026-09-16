# Change: Interactive Tours for Finalized Modules

## Why

The platform already has a working interactive tour engine (`TcloudTour` in `app/public/js/interactive-tour.js`) with spotlight SVG, directional tooltips, progress bar, and navigation. It is currently implemented for:
- Files (`files/index.blade.php`)
- Grabadores (`grabaciones_puntuales/grabadores/index.blade.php`)
- Canales (`grabaciones_puntuales/canales/index.blade.php`)

However, there are **9 finalized modules** that lack tours entirely, leaving users to discover functionality on their own. Since these modules are complete and stable, they are ideal candidates for polished, professional guided tours that improve onboarding and reduce support requests.

## What Changes

Add an interactive tour to each of the following finalized modules:

1. **Compartidos** (`shares/index.blade.php`) — how shared links work, permissions, expiration, access logs.
2. **Usuarios** (`admin/users.blade.php`) — user creation, roles, quotas, media editor toggle.
3. **Storage** (`admin/storages.blade.php`) — storage CRUD, user assignments, S3 config, filters.
4. **Editor de Medios** (`admin/media-editor.blade.php`) — enabling users, clip limits, stats.
5. **Sesiones** (`admin/sessions.blade.php`) — viewing active sessions, killing sessions, global settings.
6. **Redes** (`admin/redis.blade.php`) — monitoring Redis keys, memory, flush operations.
7. **Correo** (`admin/correo.blade.php`) — SMTP config, templates, test connection, logs.
8. **Sites Externos** (`admin/external-sites.blade.php`) — creating embedded sites, user assignments, icons.

## Non-goals

- No changes to the tour engine itself (`interactive-tour.js`).
- No new backend routes, controllers, or database migrations.
- No screenshots or image assets required (tours use DOM selectors + text descriptions).
- No modifications to module business logic.

## Impact

- **Files affected**: 8 Blade views listed above.
- **No migrations needed**.
- **No backend changes** — pure frontend additions.
- Each tour follows the existing pattern: a purple "Tour" button + `TcloudTour.start({ steps: [...] })`.

## Behavioral Rules

1. Each tour must have 4–8 steps covering the module's key actions.
2. Steps use existing DOM selectors; no new CSS classes required unless strictly necessary.
3. `onShow` callbacks navigate Alpine/tabs to the correct view before highlighting elements.
4. Tours are dismissible and skip-able at any step.
5. All text is in Spanish, professional tone, concise (1–2 sentences per step).
