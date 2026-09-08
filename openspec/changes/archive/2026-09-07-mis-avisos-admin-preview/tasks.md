## 1. Backend — Middleware

- [ ] 1.1 Create `app/app/Http/Middleware/AdminPreviewSwap.php` with constructor signature and `handle(Request $request, Closure $next): Response`. Read-only structure: detect role, validate `as_user`, swap, log, restore.
- [ ] 1.2 Implement admin check: `session('user')?->role === 'admin'`. If false, pass through without touching the session.
- [ ] 1.3 Implement `as_user` parsing: read `request()->input('as_user')`, validate is a positive integer (use `ctype_digit` + cast), if invalid → pass through.
- [ ] 1.4 Implement target-user lookup + module-enabled check: find `User::find($id)`, then check `UserAlertsInteligente::where('user_id', $id)->where('enabled', true)->exists()`. If user missing OR module off → 404 (or 403 with generic message — design decision: 404 to avoid information leak).
- [ ] 1.5 Implement swap with try/finally: save original `session('user_id')`, set `session('user_id') === $id`, set `session('admin_previewing_id') === $id`, propagate request through `$next`, then restore.
- [ ] 1.6 Emit `Log::channel('admin_preview')->info('preview.applied', [...])` with admin_id, impersonated_id, result (ok/rejected + reason), method, path, ip, ua.

## 2. Backend — Logging Configuration

- [ ] 2.1 Open `app/config/logging.php`; add a new channel `admin_preview` keyed at `'admin_preview'`, using `single` driver pointing to `storage/logs/admin-preview.log`, level `info`.
- [ ] 2.2 Verify the file `app/storage/logs/admin-preview.log` is created on first write (touch with `php artisan tinker --execute='Log::channel("admin_preview")->info("test");'`).
- [ ] 2.3 Add `storage/logs/admin-preview.log` to `.gitignore` (verify it's already excluded via the `*.log` pattern; otherwise add).

## 3. Backend — Routes

- [ ] 3.1 In `app/routes/web.php`, locate the existing `Route::middleware(['auth', 'misavisos'])->group(...)` block that contains the `/mis-avisos` routes; add `AdminPreviewSwap::class` to the middleware array, becoming `['auth', 'misavisos', AdminPreviewSwap::class]`.
- [ ] 3.2 Verify the final middleware order: `auth` (login) → `misavisos` (module-enabled) → `AdminPreviewSwap` (impersonation). The impersonation swap MUST happen AFTER the `misavisos` check for the impersonated user (so the misavisos middleware sees the impersonated session), but BEFORE the controller runs.
- [ ] 3.3 Add `Route::get('/admin/preview/impersonatable-users', [Controller::class, 'impersonatableUsers'])->middleware(['auth', 'admin'])` (in the existing `['auth', 'admin']` group).
- [ ] 3.4 Run `php artisan route:list | grep mis-avisos` and confirm all ~10 existing client routes now have the new middleware, plus the new impersonatable-users endpoint.

## 4. Backend — Impersonatable Users Endpoint

- [ ] 4.1 Decide controller placement: keep it simple, add a method `impersonatableUsers()` to `App\Http\Controllers\Admin\AvisosInteligentesController` OR a new tiny `AdminPreviewController`. Recommendation: new `app/app/Http/Controllers/Admin/AdminPreviewController.php` (single method).
- [ ] 4.2 The method queries: `User::whereHas('alertsInteligente', fn ($q) => $q->where('enabled', true))->orderBy('username')->get(['id', 'username', 'name'])`. Returns `200` with `{users: [...]}` JSON.
- [ ] 4.3 Verify in `php -r`: the controller method returns the expected JSON shape.

## 5. Frontend — Banner + Dropdown in `mis-avisos/index.blade.php`

- [ ] 5.1 At the top of the `<div x-data="misAvisosPage()">` block, insert a Blade `@if (session('admin_previewing_id'))` that renders a sticky banner with:
  - Yellow/warning background (`bg-amber-50 border-amber-300`).
  - Text "Viendo como **<username>**" rendered server-side (look up username from `session('admin_previewing_id')`).
  - Dropdown `<form action="/mis-avisos" method="GET">` with `<select name="as_user">` containing options for each impersonatable user.
  - "Volver a mi cuenta" link: simple `href="/mis-avisos"` (`as_user` empty → middleware no-op).
- [ ] 5.2 Inside the component, populate the dropdown via `fetch('/admin/preview/impersonatable-users')` (called once in `init()`), store result in `Alpine.impersonatableUsers = [...]`, render `<option>` per user.
- [ ] 5.3 Add `Alpine.onSubmit` handler on the `<form>` that, on change, simply submits (a vanilla GET form already submits). No JS needed beyond ensuring the `<option :value="cat.id">` binding is reactive.

## 6. Frontend — Propagate `as_user` to internal fetch calls

- [ ] 6.1 In `mis-avisos/index.blade.php`, expose `window.__adminPreviewUserId` (or similar) injected server-side at the top of the Blade: `window.__adminPreviewUserId = {{ session('admin_previewing_id') ?? 'null' }};`.
- [ ] 6.2 Modify the wrapper `apiFetch()` (already used by every internal call in this view) so that, if `window.__adminPreviewUserId` is set, it appends `?as_user=<id>` to the URL when the path starts with `/mis-avisos/`.
- [ ] 6.3 Verify: in the impersonation request, open the live tab — confirm internal poll calls (`/mis-avisos/feed?...`) carry `as_user=` and the response shows the impersonated user's data.

## 7. Cross-cutting — Verification & Docs

- [ ] 7.1 Manual smoke test as admin (jsuarez): open `/mis-avisos` (own data); URL bar has no `?as_user=`. Banner does NOT appear.
- [ ] 7.2 Open `/mis-avisos?as_user=2` (Multiarchivo). Banner appears. Data shown is Multiarchivo's. No redirect, no console errors.
- [ ] 7.3 Click "Volver a mi cuenta". Page navigates to `/mis-avisos` without `?as_user=`. Banner disappears. Data shown is back to admin (or 403 because admin doesn't have the module — see §7.8).
- [ ] 7.4 Switch between 2 different clients in the dropdown. Each navigation refreshes data + banner username.
- [ ] 7.5 Open the live tab and the history tab while impersonating. Confirm the data corresponds to the impersonated user.
- [ ] 7.6 As admin, edit a keyword while impersonating (change category or scope). Return to admin's own page, logout, login as the impersonated client (if credentials known) — confirm the edit is persisted for that client.
- [ ] 7.7 Try `?as_user=999` (non-existent). Expect 404.
- [ ] 7.8 Try `?as_user={id}` for a client with the module disabled. Expect 404.
- [ ] 7.9 As non-admin (any client user), try `?as_user=anything`. Expect: page renders with own data, no banner, no log entry, no error.
- [ ] 7.10 Confirm `storage/logs/admin-preview.log` shows one entry per admin impersonation request (success and rejected).
- [ ] 7.11 Run `php artisan view:clear && php artisan route:clear` once before final smoke tests.

## 8. Smoke tests against local DB

- [ ] 8.1 `php -r 'session(["user_id" => 1, "user" => (object)["role" => "admin"]]); ...'` exercise the middleware happy path: swap + restore.
- [ ] 8.2 Verify `Session::get('user_id')` is unchanged after the middleware exits, even if a downstream controller throws.
- [ ] 8.3 Verify the log entry contains all fields (admin_id, impersonated_id, method, path, ip, ua).
- [ ] 8.4 Hit `GET /admin/preview/impersonatable-users` with admin session → 200 with the user list; with client session → 403.
