## ADDED Requirements

### Requirement: Session keep-alive ping
The system MUST send a keep-alive request to the backend every 30 minutes while the user has an authenticated tab visible, in order to prevent the Laravel session (Redis, 120 min lifetime) from expiring due to inactivity.

#### Scenario: Tab is visible and user is idle
- **WHEN** an authenticated user keeps a tab visible for 30 minutes without any user interaction
- **THEN** the system automatically sends a `POST /auth/ping` request to refresh the session lifetime

#### Scenario: Tab is hidden
- **WHEN** the browser fires the `visibilitychange` event with `document.hidden === true`
- **THEN** the system MUST pause the keep-alive interval and MUST NOT send ping requests

#### Scenario: Tab returns from hidden state
- **WHEN** the browser fires the `visibilitychange` event with `document.hidden === false`
- **THEN** the system MUST send an immediate `POST /auth/ping` request
- **AND** the system MUST resume the 30-minute keep-alive interval

#### Scenario: Keep-alive request succeeds
- **WHEN** the backend responds with `200 OK` to `POST /auth/ping`
- **THEN** the session lifetime in Redis is extended by 120 minutes from that moment

#### Scenario: Keep-alive request fails with 401
- **WHEN** the backend responds with `401` and header `X-Session-Expired: 1` to `POST /auth/ping`
- **THEN** the system MUST trigger the session-expired redirect flow (toast + redirect to `/login`)

### Requirement: Graceful session-expired redirect
The system MUST detect when any authenticated API request returns `401` due to session expiry, display a soft toast notification, and redirect the user to the login page automatically — without requiring a manual page reload.

#### Scenario: Authenticated fetch returns 401 with X-Session-Expired
- **WHEN** any `apiFetch()` call receives a response with status `401` and header `X-Session-Expired: 1`
- **THEN** the system MUST display a toast with the message "Tu sesión expiró, te llevamos al login..."
- **AND** the system MUST redirect the browser to `/login` after 1500 ms

#### Scenario: Public route returns 401 without X-Session-Expired
- **WHEN** any `apiFetch()` call receives a response with status `401` and no `X-Session-Expired` header (e.g., public share expired)
- **THEN** the system MUST NOT redirect to `/login`
- **AND** the system MUST return the response unchanged to the caller

#### Scenario: Multiple concurrent requests fail with 401
- **WHEN** 3 or more `apiFetch()` calls in flight all receive `401` with `X-Session-Expired: 1` within the same tick
- **THEN** the system MUST display the toast exactly once
- **AND** the system MUST schedule the redirect exactly once
- **AND** the additional 401 responses MUST be returned to their callers as-is

### Requirement: CSRF token expiry treated as session expiry
The system MUST treat HTTP `419` responses (CSRF token mismatch) as a session-expired signal and apply the same redirect flow as for `401` with `X-Session-Expired: 1`.

#### Scenario: Fetch returns 419
- **WHEN** any `apiFetch()` call receives a response with status `419`
- **THEN** the system MUST display the session-expired toast
- **AND** the system MUST redirect the browser to `/login` after 1500 ms

### Requirement: apiFetch wrapper for fetch calls
The system MUST provide a global `apiFetch(url, options)` function that wraps `window.fetch` and applies the session-expired interception logic. All authenticated views MUST use `apiFetch` instead of raw `fetch`.

#### Scenario: Developer uses apiFetch in a view
- **WHEN** a Blade view contains `apiFetch('/api/files', { headers: {...} })`
- **THEN** the function MUST perform the same HTTP request as `fetch('/api/files', {...})`
- **AND** the returned `Response` MUST be the underlying `fetch` `Response`

#### Scenario: apiFetch never throws on 401
- **WHEN** `apiFetch()` receives a `401` response
- **THEN** the function MUST NOT throw
- **AND** it MUST return the `Response` object so callers can inspect the status

### Requirement: Session-expired marker in middleware
The `Authenticate` middleware MUST add the `X-Session-Expired: 1` response header to every `401` response it produces, so the frontend can distinguish session expiry from other 401 causes.

#### Scenario: Unauthenticated request to protected route
- **WHEN** a request hits a route guarded by `Authenticate` middleware without a valid session
- **THEN** the middleware MUST return `401`
- **AND** the response MUST include header `X-Session-Expired: 1`

#### Scenario: JSON API request without session
- **WHEN** a request with `Accept: application/json` hits a protected route without a valid session
- **THEN** the middleware MUST return `JSON 401` with body `{"error": "No authenticated"}`
- **AND** the response MUST include header `X-Session-Expired: 1`

### Requirement: Backend keep-alive endpoint
The system MUST expose a `POST /auth/ping` endpoint, protected by the `auth` middleware, that touches the current session without performing any other action and returns `200 OK` with body `{"ok": true}`.

#### Scenario: Authenticated ping
- **WHEN** an authenticated client sends `POST /auth/ping`
- **THEN** the server MUST call `Session::touch()` to extend the session lifetime
- **AND** MUST return `200 OK` with body `{"ok": true}`

#### Scenario: Unauthenticated ping
- **WHEN** an unauthenticated client sends `POST /auth/ping`
- **THEN** the server MUST return `401` (delegated to `Authenticate` middleware)

### Requirement: Session manager loads only on authenticated layouts
The session-manager script MUST be loaded exclusively from `resources/views/layouts/app.blade.php` (the main authenticated layout) and MUST NOT be loaded on the login layout or public share layouts.

#### Scenario: Authenticated page load
- **WHEN** a view extending `layouts.app` is rendered
- **THEN** the browser MUST load `/js/session-manager.js`
- **AND** the manager MUST initialize the keep-alive interval and fetch interceptor

#### Scenario: Login page load
- **WHEN** a view extending the login layout is rendered
- **THEN** the browser MUST NOT load `/js/session-manager.js`

#### Scenario: Public share page load
- **WHEN** a view for a public share link is rendered (under `/shares/{token}/...`)
- **THEN** the browser MUST NOT load `/js/session-manager.js`