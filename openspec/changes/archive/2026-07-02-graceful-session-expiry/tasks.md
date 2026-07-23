## 1. Backend: middleware & endpoint

- [x] 1.1 Add `X-Session-Expired: 1` header to all 401 responses in `app/app/Http/Middleware/Authenticate.php`
- [x] 1.2 Add `ping()` method to `app/app/Http/Controllers/AuthController.php` that calls `Session::put('_last_ping', now()->timestamp)` and returns `{"ok": true}` with `200` (Session::touch() does not exist in Laravel 13)
- [x] 1.3 Register `POST /auth/ping` route in `app/routes/web.php` behind the `auth` middleware group
- [x] 1.4 Verify locally with `php artisan route:list` that the new route appears and is protected

## 2. Frontend: session-manager.js

- [x] 2.1 Create `app/public/js/session-manager.js` with: keep-alive interval (30 min), visibilitychange handler, `apiFetch()` wrapper, `X-Session-Expired` interceptor, 419 handling, redirect-debounce guard, and global toast element creator
- [x] 2.2 Export `apiFetch` on `window` for use by inline Alpine scripts
- [x] 2.3 Ensure the keep-alive uses raw `fetch` (NOT `apiFetch`) to avoid infinite loops if `/auth/ping` itself returns 401
- [x] 2.4 Self-initialize on `DOMContentLoaded`: start interval, register visibility listener, install fetch interceptor

## 3. Layout wiring

- [x] 3.1 Add `<script src="/js/session-manager.js" defer></script>` to `app/resources/views/layouts/app.blade.php` (only)
- [x] 3.2 Confirm `app/resources/views/auth/login.blade.php` and public share layouts do NOT load the manager
- [x] 3.3 Fix: use `if(empty(trim(@yieldContent('skip_session_manager'))))` instead of `@hasSection` because Laravel 13's `@hasSection` checks content emptiness, not section definition
- [x] 3.4 Mark public/pre-auth views with `@section('skip_session_manager', '1')`:
  - `auth/login.blade.php`
  - `shares/public.blade.php`
  - `shares/public-not-found.blade.php`
  - `shares/public-expired.blade.php`
  - `shares/public-password.blade.php`
  - `shares/preview.blade.php`
  - `files/preview.blade.php`

## 4. View migration: fetch → apiFetch

- [x] 4.1 Migrate `app/resources/views/files/index.blade.php` (29 fetch calls)
- [x] 4.2 Migrate `app/resources/views/admin/storages.blade.php` (12 fetch calls)
- [x] 4.3 Migrate `app/resources/views/admin/correo.blade.php` (9 fetch calls)
- [x] 4.4 Migrate `app/resources/views/admin/postgres.blade.php` (8 fetch calls)
- [x] 4.5 Migrate `app/resources/views/admin/external-sites.blade.php` (7 fetch calls)
- [x] 4.6 Migrate `app/resources/views/admin/redis.blade.php` (6 fetch calls)
- [x] 4.7 Migrate `app/resources/views/admin/users.blade.php` (5 fetch calls)
- [x] 4.8 Migrate `app/resources/views/admin/storage-users.blade.php` (5 fetch calls)
- [x] 4.9 Migrate `app/resources/views/admin/media-editor.blade.php`
- [x] 4.10 Migrate `app/resources/views/admin/user-storages.blade.php`
- [x] 4.11 Migrate `app/resources/views/admin/sessions.blade.php` (preserve existing 401 handler — replace with implicit one)
- [x] 4.12 Migrate `app/resources/views/shares/index.blade.php`
- [x] 4.13 `shares/public.blade.php` keeps raw `fetch(` (manager not loaded on public layout, behavior preserved)
- [x] 4.14 Migrate `app/resources/views/dashboard/user.blade.php`
- [x] 4.15 Migrate `app/resources/views/grabaciones_puntuales/grabadores/index.blade.php`
- [x] 4.16 Migrate `app/resources/views/grabaciones_puntuales/canales/index.blade.php`
- [x] 4.17 Migrate `app/resources/views/grabaciones_puntuales/canales/edit.blade.php`
- [x] 4.18 Confirmed: zero raw `fetch(` in migrated views; only 4 remain in `shares/public.blade.php` (intentional)

## 5. Manual testing

- [ ] 5.1 Login, leave tab open for 31 minutes with no interaction, verify session still active in `/admin/sessions`
- [ ] 5.2 Login, change `SESSION_LIFETIME` to 1 min locally, wait, trigger any fetch — verify toast + redirect to `/login`
- [ ] 5.3 Login, open tab, hide it for 2 min with `SESSION_LIFETIME=1`, unhide — verify toast + redirect
- [ ] 5.4 Trigger 3 parallel fetches that return 401 — verify only one toast and one redirect
- [ ] 5.5 Simulate CSRF mismatch (clear `XSRF-TOKEN` cookie, retry a POST) — verify 419 triggers same flow
- [ ] 5.6 Open a public share link as anonymous user, wait for share expiry if applicable, verify NO redirect to `/login`
- [ ] 5.7 Test in two browsers concurrently (Chrome + Firefox) to verify no shared-state issues

## 6. Deployment & docs

- [ ] 6.1 Bump no env vars; confirm `.env.example` unchanged
- [ ] 6.2 Verify no DB migrations required
- [ ] 6.3 Commit changes with conventional commit message (`feat: graceful session expiry with keep-alive`)
- [ ] 6.4 Deploy to production with PHP-FPM cache clear + restart (no other infra changes)