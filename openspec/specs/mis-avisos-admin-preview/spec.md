## Purpose

Lets an administrator open `/mis-avisos` and view, manage, and edit a client's Mis Avisos module data as if they were that client (including themselves), without altering the administrator's own session or the client's session. Impersonation is per-request and non-sticky.

## Requirements

### Requirement: Admin can preview a client's Mis Avisos data via URL parameter
The system SHALL allow an authenticated user with `role === 'admin'` to open `/mis-avisos?as_user={impersonatedUserId}` and have the page render with the data of `impersonatedUserId` instead of the administrator's own.

#### Scenario: Admin opens preview with a valid client id
- **WHEN** the authenticated session user has `role === 'admin'` AND the request URL contains `?as_user={id}` AND the target user has `user_alerts_inteligentes.enabled = true`
- **THEN** the page MUST render with the keywords, categories, matches, preferences, and storage scope of `{id}`, not the administrator's own data

#### Scenario: Non-admin attempts to pass `as_user`
- **WHEN** the authenticated session user is NOT `role === 'admin'` AND the request URL contains `?as_user={id}`
- **THEN** the system MUST ignore the parameter and render data for `session('user_id')` as if it were not present (no exception, no error)

#### Scenario: Admin passes the id of a user without the module enabled
- **WHEN** the authenticated session user has `role === 'admin'` AND the target user has no `user_alerts_inteligentes` row OR `enabled = false`
- **THEN** the system MUST return HTTP 404 with a generic "not found" message (no information leak about whether the user exists)

#### Scenario: Admin passes an `as_user` value that is not a valid integer
- **WHEN** the `as_user` parameter is non-numeric, zero, negative, or contains characters other than digits
- **THEN** the system MUST ignore the parameter and render data for `session('user_id')` as if it were not present

### Requirement: Impersonation is non-sticky and never alters the underlying session
The system MUST restore the administrator's original `session('user_id')` value at the end of every request that initiated an impersonation swap, including on exceptions. The impersonation MUST NOT persist across requests, navigations, or browser tabs.

#### Scenario: Restoration on success
- **WHEN** the request finishes normally (no exception)
- **THEN** the original `session('user_id')` MUST be restored before the response is returned to the client

#### Scenario: Restoration on exception
- **WHEN** any downstream middleware, controller, or service throws an exception during the request
- **THEN** the `finally` block of the impersonation swap MUST restore the original `session('user_id')` before the error response is generated

#### Scenario: Cross-request non-stickiness
- **WHEN** the administrator reloads the page or navigates to any URL without `?as_user=`
- **THEN** the page MUST render with the administrator's own data, never the impersonated user's

### Requirement: Admin-only preview banner with client switcher and revert action
When impersonation is active in a request, the Mis Avisos view MUST display a persistent banner at the top of the page showing: the impersonated user's display name, a dropdown to switch to a different client, and a "Volver a mi cuenta" link.

#### Scenario: Banner is rendered for an impersonated request
- **WHEN** the request carries `?as_user={id}` and the admin is impersonating
- **THEN** the banner MUST appear at the top of the page with the impersonated user's display name, a dropdown listing all clients with the module enabled (sorted alphabetically by username), and a "Volver a mi cuenta" link

#### Scenario: Banner is NOT rendered for non-admin users
- **WHEN** the authenticated session user is NOT `role === 'admin'`
- **THEN** the banner MUST NOT appear regardless of `?as_user` presence

#### Scenario: Banner is NOT rendered when admin views own data
- **WHEN** the administrator opens `/mis-avisos` without any `?as_user` parameter
- **THEN** no banner is rendered — the page looks identical to a regular client view

#### Scenario: Switching clients via the dropdown
- **WHEN** the admin selects a different client in the dropdown
- **THEN** the page MUST navigate to `/mis-avisos?as_user={otherId}` and render that client's data

#### Scenario: Reverting to own account
- **WHEN** the admin clicks "Volver a mi cuenta"
- **THEN** the page MUST navigate to `/mis-avisos` (no query parameter) and render the admin's own data

### Requirement: Admin can read and edit impersonated client's data
While impersonating, the admin MUST be able to perform any action the impersonated client can perform on the Mis Avisos module — including creating, editing, and deleting keywords; creating, editing, and deleting categories; and changing per-keyword storage scope. No additional permission gate SHALL prevent these actions.

#### Scenario: Add a keyword as impersonated client
- **WHEN** the admin submits POST `/mis-avisos/keywords` while impersonating client X
- **THEN** the keyword MUST be associated with client X's `user_keyword` pivot row (not the admin's)

#### Scenario: Delete a category as impersonated client
- **WHEN** the admin submits DELETE `/mis-avisos/categories/{id}` while impersonating client X
- **THEN** the category MUST be deleted iff it belongs to client X; the response MUST be the same the client would have received

#### Scenario: Stale `EnsureMisAvisosEnabled` middleware check
- **WHEN** the request is impersonating client X and the impersonation middleware runs ahead of `misavisos`
- **THEN** `misavisos` MUST see `session('user_id') === X.id` and check X's `user_alerts_inteligentes.enabled`, not the admin's

### Requirement: Impersonation events are logged for audit
Every request that triggers an impersonation swap MUST emit one structured log entry with: admin's user id, impersonated user id, request method, request path, IP address, and user-agent. The log MUST NOT contain passwords, tokens, or query parameter values other than `as_user`.

#### Scenario: Successful impersonation request
- **WHEN** the impersonation middleware swaps the session and the downstream request completes
- **THEN** one log entry at level `info` MUST be written to the `admin_preview` channel with the metadata above

#### Scenario: Failed impersonation (target does not exist or no module)
- **WHEN** the `as_user` parameter references a user that does not exist or has the module disabled
- **THEN** one log entry at level `info` MUST still be written, with a `result = rejected` field and the `reason` (no such user OR module disabled)

### Requirement: Impersonatable user list is sourced from enabled-module users only
The dropdown of clients available in the banner MUST contain exactly the users whose `user_alerts_inteligentes.enabled = true`, ordered alphabetically by `username`, with the impersonated user removed from the list only when navigating away (so the dropdown always re-evaluates on a fresh request).

#### Scenario: Dropdown contains only module-enabled users
- **WHEN** the banner is rendered for an admin request
- **THEN** the dropdown options MUST contain only users with `user_alerts_inteligentes.enabled = true`

#### Scenario: Dropdown excludes users with module disabled
- **WHEN** a user exists with `user_alerts_inteligentes.enabled = false`
- **THEN** that user MUST NOT appear in the dropdown options

#### Scenario: Dropdown ordering
- **WHEN** the dropdown is rendered
- **THEN** entries MUST be ordered alphabetically by `username` (case-insensitive)

#### Scenario: Dropdown endpoint is admin-only
- **WHEN** a non-admin requests the dropdown data source
- **THEN** the response MUST be HTTP 403

### Requirement: No data leakage between admin preview and client real account
The impersonation swap MUST NOT affect, modify, or expose any data belonging to the impersonated user's actual session — only the in-memory `session('user_id')` for the duration of the impersonating request.

#### Scenario: Impersonated client's own browser is unaffected
- **WHEN** the admin impersonates client X
- **THEN** client X's own browser (if they happen to be logged in simultaneously) MUST continue to see their own data and any changes the admin made during preview MUST appear in client X's next visit

#### Scenario: Admin impersonating themselves
- **WHEN** the administrator passes `?as_user={their-own-id}`
- **THEN** the system MUST work exactly as a non-impersonated request: no banner, no swap, no log entry (because no actual swap occurred)
