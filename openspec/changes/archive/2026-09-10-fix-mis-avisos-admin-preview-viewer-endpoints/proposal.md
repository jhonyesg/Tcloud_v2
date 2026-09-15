## Why

When an admin opens Mis Avisos with `?as_user={clientId}` to preview a client's
data, clicking "Ver transcripción" on a hit row opens the unified transcript
viewer modal which fetches `/files/{file}/transcription` via `apiFetch()`. That
endpoint lives outside the `AdminPreviewSwap` middleware group (`routes/web.php:397`)
and the apiFetch prefix whitelist (`mis-avisos/index.blade.php:30`) only matches
URLs starting with `/mis-avisos`. The result: the swap never applies, the
controller runs as the admin session, `MentionsSearchService::visibleTranscription`
returns `null` for any storage the admin has no `transcription_access` for, and
the modal shows "No se pudo cargar la transcripción". Reproduced live on
`kalle_10092026_064501.mp4` (storage 26 "21 Kalle tv", admin jsuarez has no
access; client 3 "Multiarchivo" does).

The bug is structural: the unified viewer was introduced 2026-09-10
(`mis-archivos-transcript-viewer`) and its endpoints were never wired into the
admin preview flow. Today every file in a storage the admin doesn't personally
have `transcription_access` for fails when previewed — that's the majority of
storages for client-only modules.

## What Changes

- Move the endpoints the unified viewer (and its clip flow) calls into the
  `Route::middleware(['auth', 'misavisos', AdminPreviewSwap::class])` group in
  `routes/web.php`:
  - `GET /files/{file}/transcription` (FileController@transcription)
  - `GET /files/{file}/preview` (FileController@preview)
  - `GET /files/{file}/clip-thumbs` (MediaClipController@thumbnails)
  - `GET /files/{file}/clip-thumb/{n}` (MediaClipController@thumb)
- Replace the prefix-string filter in `mis-avisos/index.blade.php` patch with a
  server-rendered constant exposed via `window.__adminPreviewAwarePrefixes`
  so the apiFetch wrapper and the middleware group share one source of truth.
- Document the preview-aware endpoint list inside `AdminPreviewSwap` as a
  `PREVIEW_AWARE_PREFIXES` constant so future endpoints are added in one place.

Impersonation stays per-request (`?as_user=X`) — no sticky session, no scope
creep to other modules, no change to `user_role` semantics. The per-user
`transcription_access` model is unchanged: a client without access still sees
nothing, and a non-admin client cannot impersonate.

## Capabilities

### New Capabilities
- `admin-preview-aware-endpoints`: declarative list of URL prefixes whose
  endpoints participate in admin preview when `?as_user=X` is present;
  documents the contract between the apiFetch patch and the middleware group.

### Modified Capabilities
- `mis-avisos-admin-preview`: add a new requirement describing which
  endpoints honor `?as_user=X` (the unified viewer endpoints) and the source
  of truth for the prefix list. Existing non-sticky behavior, banner
  semantics, role gating, and "self-impersonation is a no-op" rule are
  preserved.

## Impact

- `app/routes/web.php` — four routes moved into the existing
  `['auth', 'misavisos', AdminPreviewSwap]` group.
- `app/resources/views/mis-avisos/index.blade.php` — apiFetch patch
  refactored to read `window.__adminPreviewAwarePrefixes`.
- `app/resources/views/layouts/app.blade.php` — expose the constant from
  `AdminPreviewSwap::PREVIEW_AWARE_PREFIXES` so any page that hosts the
  unified viewer can consume the same list.
- `app/app/Http/Middleware/AdminPreviewSwap.php` — add `PREVIEW_AWARE_PREFIXES`
  constant + docblock.
- `openspec/specs/mis-avisos-admin-preview/spec.md` — additive scenario for
  the unified viewer endpoints.
- No migrations. No schema changes. No impact on other modules: clients still
  see only their `user_storages`; admin without `?as_user=` still sees only
  their own data; admin role is preserved throughout.
