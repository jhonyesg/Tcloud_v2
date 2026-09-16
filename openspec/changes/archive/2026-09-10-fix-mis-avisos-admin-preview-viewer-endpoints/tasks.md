## 1. Middleware contract

- [x] 1.1 Add `public const PREVIEW_AWARE_PREFIXES = ['mis-avisos', 'files', 'media']` to `App\Http\Middleware\AdminPreviewSwap` with a docblock that documents the contract (single source of truth for routes group + apiFetch wrapper).
- [x] 1.2 Verify the constant is readable from a Blade context via `\App\Http\Middleware\AdminPreviewSwap::PREVIEW_AWARE_PREFIXES` (sanity check via `php -r` or `php artisan tinker`).

## 2. Routes group reorganization

- [x] 2.1 In `app/routes/web.php`, move `Route::get('/files/{file}/transcription', ...)` from the `Route::middleware('auth')->group(...)` block (line ~134) into the `Route::middleware(['auth', 'misavisos', AdminPreviewSwap::class])` group (line 397).
- [x] 2.2 Move `Route::get('/files/{file}/preview', ...)` (line ~127) into the same admin-preview group.
- [x] 2.3 Move `Route::get('/files/{file}/clip-thumbs', ...)` (MediaClipController@thumbnails) into the same group.
- [x] 2.4 Move `Route::get('/files/{file}/clip-thumb/{n}', ...)` (MediaClipController@thumb) into the same group.
- [x] 2.5 Verify each moved route still works for the regular (non-preview) call path: log in as a normal client with access, request each endpoint, confirm HTTP 200.

## 3. Layout injection

- [x] 3.1 In `app/resources/views/layouts/app.blade.php`, add a `<script>` block (or extend the existing init script) that emits `window.__adminPreviewAwarePrefixes = @json(\App\Http\Middleware\AdminPreviewSwap::PREVIEW_AWARE_PREFIXES);`.
- [x] 3.2 Verify the variable is present on every authenticated page (curl `/mis-avisos`, `/files`, `/profile` while logged in; grep response for `__adminPreviewAwarePrefixes`).

## 4. apiFetch wrapper refactor

- [x] 4.1 In `app/resources/views/mis-avisos/index.blade.php`, replace the hardcoded `url.indexOf('/mis-avisos') === 0` check inside the IIFE patch with a check against `window.__adminPreviewAwarePrefixes` using `Array.prototype.some(p => url.indexOf('/' + p) === 0)`.
- [x] 4.1.1 CRITICAL: the prefix list MUST be read on each call to `apiFetch`, NOT captured into closure when the IIFE runs. The layout defines `window.__adminPreviewAwarePrefixes` AFTER `@yield('content')`, so when the IIFE runs (inside the yield), the layout constant is not yet set and the fallback `['mis-avisos']` is captured. Without per-call re-read, the patch only covers `/mis-avisos/*` and silently bypasses `/files/*` and `/media/*` — reproducing the original bug.
- [x] 4.2 Keep the `url.indexOf('as_user=') === -1` guard so duplicate `?as_user=` cannot be appended.
- [x] 4.3 Keep the `window.__adminPreviewWired` idempotency guard.

## 5. Manual end-to-end verification

- [x] 5.1 Log in as admin (jsuarez). Without impersonation, click "Ver transcripción" on a City TV row in Mis Avisos: confirm the modal loads (admin has transcription_access for storage 8 today; this validates the baseline is unbroken).
- [x] 5.2 Open `/mis-avisos?as_user=3`. Confirm the impersonation banner appears.
- [x] 5.3 Click "Ver transcripción" on a `21 Kalle tv` Apple row (file 6922976, storage 26, admin has no transcription_access, client 3 does). Confirm the modal loads with metadata + segments (previously returned "No se pudo cargar la transcripción").
- [x] 5.4 Open DevTools Network tab during step 5.3 and confirm the request URL is `/files/6922976/transcription?anchor_segment_id=...&as_user=3`.
- [x] 5.5 Click "Generar corte" inside the modal. Confirm the clip-thumbs requests also carry `as_user=3` and return 200.
- [x] 5.6 Log in as a non-admin client. Open `/mis-avisos` (no `?as_user`). Confirm the wrapper does NOT alter URLs (no `as_user` is added because `window.__adminPreviewUserId` is null).

## 6. Negative-path verification (no regressions)

- [x] 6.1 As admin, open `/files` (Mis Archivos) without `?as_user`. Confirm the page renders the admin's own files (storage 8, 30, etc.) — no impersonation leak.
- [x] 6.2 As a non-admin client, request `GET /mis-avisos?as_user=1` (admin). Confirm the server ignores the parameter (returns the client's own data, not the admin's).
- [x] 6.3 As admin, request `GET /files/{file}/transcription` for a file in a storage the admin has `transcription_access` for, without `?as_user`. Confirm 200 OK (no regression in the regular auth path).
- [x] 6.4 Confirm `php artisan route:list` shows the four moved routes under the new middleware group and no orphan references to them in the old group.

## 7. Spec archive prep

- [x] 7.1 Confirm the change directory passes `openspec validate fix-mis-avisos-admin-preview-viewer-endpoints` without errors.
- [x] 7.2 Confirm the change is `apply-ready` via `openspec status --change fix-mis-avisos-admin-preview-viewer-endpoints`.
