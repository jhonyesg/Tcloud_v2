## MODIFIED Requirements

### Requirement: Session keep-alive ping
The system MUST send a keep-alive request to the backend every 30 minutes while the user has an authenticated tab visible, in order to prevent the session from expiring due to inactivity. The backend MUST renew the session's `expires_at` in `user_sessions` on every authenticated request (including pings), implementing a sliding inactivity timeout rather than an absolute timeout from login.

#### Scenario: Tab is visible and user is idle
- **WHEN** an authenticated user keeps a tab visible for 30 minutes without any user interaction
- **THEN** the system automatically sends a `POST /auth/ping` request to refresh the session

#### Scenario: Tab is hidden
- **WHEN** the browser fires the `visibilitychange` event with `document.hidden === true`
- **THEN** the system MUST pause the keep-alive interval and MUST NOT send ping requests

#### Scenario: Tab returns from hidden state
- **WHEN** the browser fires the `visibilitychange` event with `document.hidden === false`
- **THEN** the system MUST send an immediate `POST /auth/ping` request
- **AND** the system MUST resume the 30-minute keep-alive interval

#### Scenario: Keep-alive request succeeds
- **WHEN** the backend responds with `200 OK` to `POST /auth/ping`
- **THEN** the session `expires_at` in `user_sessions` MUST be renewed to `now + session_lifetime` (sliding timeout)
- **AND** the Redis session cookie lifetime MUST be extended accordingly

#### Scenario: Keep-alive request fails with 401
- **WHEN** the backend responds with `401` and header `X-Session-Expired: 1` to `POST /auth/ping`
- **THEN** the system MUST trigger the session-expired redirect flow (toast + redirect to `/login`)

### Requirement: Backend keep-alive endpoint
The system MUST expose a `POST /auth/ping` endpoint, protected by the `auth` middleware, that touches the current session without performing any other action and returns `200 OK` with body `{"ok": true}`. The `SessionTracker` middleware MUST renew `expires_at` in `user_sessions` when processing the ping request, just as it does for any other authenticated request.

#### Scenario: Authenticated ping
- **WHEN** an authenticated client sends `POST /auth/ping`
- **THEN** the `SessionTracker` middleware MUST renew `expires_at` in `user_sessions` to `now + session_lifetime`
- **AND** the controller MUST return `200 OK` with body `{"ok": true}`

#### Scenario: Unauthenticated ping
- **WHEN** an unauthenticated client sends `POST /auth/ping`
- **THEN** the server MUST return `401` (delegated to `Authenticate` middleware)