## Context

See `proposal.md` for the motivation and `../specs/admin-preview-aware-endpoints/spec.md`
plus the delta in `../specs/mis-avisos-admin-preview/spec.md` for the requirements
this design satisfies. The current state relevant to implementation:

- `routes/web.php:104` — `Route::middleware('auth')->group(...)` holds every
  general file/media route (`/files/{file}/...`, `/media/{file}/...`).
- `routes/web.php:397` — the `['auth', 'misavisos', AdminPreviewSwap]` group
  holds only the Mis Avisos JSON endpoints.
- `app/app/Http/Middleware/AdminPreviewSwap.php` — per-request swap; reads
  `?as_user=X`, validates admin role and `user_alerts_inteligentes.enabled`,
  swaps `session('user_id')` in `try { ... } finally { restore }` and clears
  `admin_previewing_id` / `admin_original_user_id` at the end of the request.
- `app/resources/views/mis-avisos/index.blade.php:21-36` — patches
  `window.apiFetch` to auto-append `?as_user=X` to URLs starting with
  `/mis-avisos` when `window.__adminPreviewUserId` is set.
- `app/resources/views/layouts/app.blade.php:804` — `Alpine.store('transcriptViewer').openFor()`
  calls `GET /files/{file_id}/transcription?anchor_segment_id=...` when the
  viewer is opened (used from both Mis Archivos and Mis Avisos).

The unified viewer endpoint and three clip-related endpoints are the four
routes that participate in Mis Avisos preview flow but currently live outside
the middleware group.

## Goals / Non-Goals

**Goals:**

- Make `/files/{file}/transcription`, `/files/{file}/preview`,
  `/files/{file}/clip-thumbs` and `/files/{file}/clip-thumb/{n}` honor
  `?as_user=X` when wrapped by the existing `AdminPreviewSwap` middleware.
- Establish a single source of truth for which URL prefixes participate in
  admin preview, consumed by both the middleware (for route placement) and
  the apiFetch patch (for `?as_user=` propagation).
- Keep impersonation per-request, per spec: no session stickiness, no
  banner changes, no role changes.

**Non-Goals:**

- No sticky impersonation. No "exit preview" endpoint. No banner move.
- No change to other modules (Mis Archivos, IA, profile, etc.).
- No relaxation of `user_role === 'admin'` gating.
- No change to per-user `transcription_access` enforcement.
- No change to `MentionsSearchService` or `FileController::transcription`
  business logic.

## Decisions

### Decision 1: Move routes (don't copy middleware)

Move the four routes from the `Route::middleware('auth')->group(...)` block
into the existing `['auth', 'misavisos', AdminPreviewSwap]` group. Rationale:
a single application of the middleware is cleaner than re-applying it on each
route; the `misavisos` gate is appropriate because these routes are only
called from Mis Avisos-driven flows (transcript viewer, clip flow). The
`misavisos` middleware is a soft client-side gate that returns 403 if the
client hasn't enabled the module, but admin impersonation runs ahead of it
so admin's impersonated view still works (validated during design walkthrough:
`AdminPreviewSwap` swaps `user_id` before `misavisos` checks the swapped
user's module flag).

Alternatives considered:

- **Copy `AdminPreviewSwap` onto each route individually** — duplicates the
  middleware application, easier to forget on future endpoints. Rejected.
- **Apply `AdminPreviewSwap` to the whole `auth` group** — too broad; would
  cause impersonation attempts on `/profile`, `/user/sessions`, etc., and
  violate the "other modules not affected" constraint. Rejected.

### Decision 2: Single source of truth via PHP constant

Add `AdminPreviewSwap::PREVIEW_AWARE_PREFIXES = ['mis-avisos', 'files',
'media']` (constant). Expose it from a Blade include via
`window.__adminPreviewAwarePrefixes = @json(\App\Http\Middleware\AdminPreviewSwap::PREVIEW_AWARE_PREFIXES)`.
The apiFetch patch in `mis-avisos/index.blade.php` reads from
`window.__adminPreviewAwarePrefixes` instead of hardcoded `'mis-avisos'`.

Rationale: the contract "these URL prefixes participate in admin preview"
lives in one place. A future endpoint added to the middleware group also
needs its prefix in the apiFetch whitelist; updating the constant and the
routes group together is the natural extension point.

Alternatives considered:

- **Hardcode the prefix list in the Blade patch** — current state, fragile
  (this is what caused the bug).
- **Read prefixes from a JSON config file at runtime** — extra file,
  no real benefit over a PHP constant for a static list. Rejected.
- **Compute prefixes from the middleware group definition** — possible via
  `Route::getRoutes()->getRoutesByName()` but coupling runtime introspection
  to Blade is heavy. Rejected for MVP.

### Decision 3: Where to expose `window.__adminPreviewAwarePrefixes`

Inject from `layouts/app.blade.php` (the layout used by every authenticated
page). The apiFetch wrapper itself lives only in `mis-avisos/index.blade.php`
today, but exposing globally keeps the option open for Mis Archivos or
admin tooling to use the same viewer in the future without re-injecting.

Rationale: zero-cost (one JSON literal in the layout) and future-proof. The
patch only activates when `window.__adminPreviewUserId` is set, so global
exposure does not change behavior on non-impersonating pages.

### Decision 4: apiFetch patch refactor

Replace the prefix check in the patch:

```js
// Before
if (sid && typeof url === 'string'
    && url.indexOf('/mis-avisos') === 0
    && url.indexOf('as_user=') === -1) { ... }

// After
if (sid && typeof url === 'string'
    && Array.isArray(window.__adminPreviewAwarePrefixes)
    && window.__adminPreviewAwarePrefixes.some(p => url.indexOf('/' + p) === 0)
    && url.indexOf('as_user=') === -1) { ... }
```

`Array.some` with `indexOf('/' + p) === 0` matches `/mis-avisos/...`,
`/files/...`, `/media/...` but not `mis-avisos/foo` (relative). Rationale:
matches the original behavior's left-anchored match but generalized.

**Sub-decision (CRITICAL, discovered during e2e verification 2026-09-10):**
the prefix list MUST be re-read on every `apiFetch` call inside the IIFE.
The original implementation captured `awarePrefixes` once at IIFE setup
time via `var awarePrefixes = ...` — but the layout defines
`window.__adminPreviewAwarePrefixes` AFTER `@yield('content')` in the
rendered HTML. As a result the IIFE always ran with the fallback
`['mis-avisos']` and silently bypassed `/files/*` and `/media/*` URLs,
reproducing the original bug exactly. Verified via console logging
inside the patched function: `/files/...` URLs were logging
`aware=false` even though `window.__adminPreviewAwarePrefixes` was
correctly populated to `["mis-avisos","files","media"]` at that moment.

Fix: read `window.__adminPreviewAwarePrefixes` on every call instead of
caching it in the closure. Move the array read + the `isAware()` helper
inside the function body.

## Risks / Trade-offs

- **`misavisos` middleware may reject admin impersonating an inactive user.**
  Mitigated: `AdminPreviewSwap` runs ahead of `misavisos` and validates
  `UserAlertsInteligente::enabled` before swap; if validation fails it
  aborts with 404 before reaching `misavisos`.
- **Routes moved out of `auth` group lose nothing**: `auth` is the only
  middleware in that group; the new group is `auth + misavisos + AdminPreviewSwap`
  so auth coverage is preserved.
- **Clip endpoints (`/clip-thumbs`, `/clip-thumb/{n}`)**: the clip flow is
  triggered from the unified viewer modal. Without the middleware, the
  admin's impersonated clip attempt would run as admin and could fail the
  same way the transcription endpoint does. Including them in this fix
  prevents a parallel bug. Trade-off: small additional surface change.
- **No automated regression test for the unified viewer flow** exists today;
  manual verification via Playwright or a curl script will be needed (see
  tasks).
- **Existing sessions impersonating at deploy time**: the per-request swap
  model means there is no persistent impersonation state to invalidate.
  No deploy concern.

## Migration Plan

No data migration. No schema changes. Deploy is pure code + routes:

1. Move the four route lines in `routes/web.php`.
2. Add the constant in `AdminPreviewSwap.php`.
3. Update the Blade patch to read the prefix list.
4. Inject the prefix list in `layouts/app.blade.php`.
5. Manual verification: impersonate Multiarchivo as jsuarez, click
   `21 Kalle tv` Apple row, confirm modal loads the transcript.

Rollback: `git revert` the commit; routes move back to the `auth` group, the
constant and Blade change are no-ops on their own. Per-request impersonation
behaves identically to today (broken for the unified viewer endpoints,
working for `/mis-avisos/*`).

## Open Questions

None. The scope is narrow enough that all design choices can be made now:
prefix list is `['mis-avisos', 'files', 'media']`; the constant is
PHP-public; the Blade patch is a 4-line refactor; layout injection is one
line; tests are manual + curl-based for MVP.
