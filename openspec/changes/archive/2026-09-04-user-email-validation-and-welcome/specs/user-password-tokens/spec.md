## Purpose

Reemplaza el token de recuperación de contraseña que hoy vive en `$_SESSION` (solo funcional dentro del mismo navegador) por un token persistente en base de datos, y unifica el ciclo de vida de "establecer contraseña inicial" con "recuperar contraseña" bajo un mismo servicio.

## ADDED Requirements

### Requirement: Persistent password tokens
The system SHALL persist all password tokens in a dedicated database table keyed by a one-way hash of the random token. Raw tokens SHALL never be stored.

#### Scenario: Token issuance
- **WHEN** a token is issued for a user
- **THEN** the system generates a cryptographically random token (minimum 32 bytes), stores only its hash, associates it with the user, a type, and an expiration timestamp, and returns the raw token to the caller exactly once

#### Scenario: Token consumption
- **WHEN** a token is presented for consumption
- **THEN** the system hashes it, looks up the matching record, validates expiration and unused status, marks it as used, and records the consuming IP

#### Scenario: Expired token
- **WHEN** a token is presented after its expiration timestamp
- **THEN** the system rejects it with a generic "invalid or expired token" outcome and does not perform the side-effect

#### Scenario: Already-used token
- **WHEN** a token has already been consumed
- **THEN** the system rejects the second use with the same generic outcome

### Requirement: Two token types
The system SHALL distinguish two token types: `setup` (for first-time password set after account creation) and `reset` (for password recovery from the login screen).

#### Scenario: Setup token side-effect
- **WHEN** a `setup` token is successfully consumed
- **THEN** the system materializes the user's password hash, transitions the user to active status, and creates a personal storage provider if and only if the user has a username

#### Scenario: Reset token side-effect
- **WHEN** a `reset` token is successfully consumed
- **THEN** the system only updates the user's password hash; it does not change status or create storage

### Requirement: Single active token per user and type
The system SHALL invalidate any previous unconsumed token of the same type for a user when a new one is issued, so that a user has at most one active token per type at any time.

#### Scenario: New setup token replaces old
- **WHEN** a new `setup` token is issued for a user that already has an unused `setup` token
- **THEN** the previous token is marked as invalidated and only the new one is accepted

### Requirement: Token expiration window
The system SHALL expire tokens after a configurable window. The default SHALL be 24 hours.

#### Scenario: Token within window
- **WHEN** a token is consumed before its expiration timestamp
- **THEN** consumption proceeds normally

#### Scenario: Token past window
- **WHEN** a token is consumed after its expiration timestamp
- **THEN** consumption is rejected regardless of other state

### Requirement: Generic outcome for invalid token
The system SHALL return the same generic outcome for "token not found", "token expired", and "token already used", so that no information is leaked about the validity of any specific token.

#### Scenario: Lookup miss, expiry, and reuse are indistinguishable
- **WHEN** any of the three invalid conditions is triggered
- **THEN** the caller receives the same generic response
