## ADDED Requirements

### Requirement: Sliding session expiry renewal on activity
The `SessionTracker` middleware SHALL renew `expires_at` in `user_sessions` whenever it renews `last_activity_at` (i.e., at most once per 60 seconds per active session). The new `expires_at` MUST be calculated as `now() + session_lifetime` using `SessionService::getEffectiveLifetimeMinutes()` to respect per-user and global lifetime overrides.

#### Scenario: Active request after 60 seconds of last renewal
- **WHEN** an authenticated request arrives and `last_activity_at` was last updated more than 60 seconds ago
- **THEN** the middleware MUST update both `last_activity_at` and `expires_at` in a single `UPDATE` query
- **AND** `expires_at` MUST equal `now() + effectiveLifetimeMinutes`

#### Scenario: Active request within 60 seconds of last renewal
- **WHEN** an authenticated request arrives and `last_activity_at` was updated less than 60 seconds ago
- **THEN** the middleware MUST NOT update `expires_at` or `last_activity_at` (throttled)

#### Scenario: User with unlimited lifetime (lifetime=0)
- **WHEN** `SessionService::getEffectiveLifetimeMinutes()` returns 0 for a user
- **THEN** the middleware MUST NOT set `expires_at` (leave it as null) and the session MUST NOT expire by inactivity

### Requirement: Cache invalidation after expiry renewal
The `SessionTracker` middleware SHALL invalidate the `session_valid:{session_id}` cache key immediately after renewing `expires_at`, so that the next request re-validates against the database with the updated expiry.

#### Scenario: Expiry renewed, cache invalidated
- **WHEN** the middleware renews `expires_at` in `user_sessions`
- **THEN** the middleware MUST call `Cache::forget("session_valid:{session_id}")`
- **AND** the next request MUST re-query `user_sessions` to confirm the renewed `expires_at`

### Requirement: Session lifetime default of 24 hours
The system SHALL default `SESSION_LIFETIME` to 1440 minutes (24 hours) in `.env` and `.env.example`, so that the sliding inactivity window allows a full day of continuous use without forced re-login.

#### Scenario: Fresh deployment with default env
- **WHEN** a deployment uses the default `.env.example` configuration
- **THEN** `SESSION_LIFETIME` MUST be 1440
- **AND** sessions MUST expire only after 24 hours of inactivity, not 2 hours from login