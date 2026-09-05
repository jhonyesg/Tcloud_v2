## Purpose

Asegura que todo usuario recién creado con un correo entregable reciba automáticamente una bienvenida con un enlace para establecer su contraseña, y define el ciclo de vida que activa la cuenta sin intervención del admin.

## ADDED Requirements

### Requirement: Mandatory email validation at creation
The system SHALL refuse to create a user when the provided email does not pass the deliverability check, returning a clear error to the admin UI without creating any record.

#### Scenario: Admin creates user with valid email
- **WHEN** the admin submits a user creation form with an email that passes deliverability validation
- **THEN** the user is created and the welcome setup flow is initiated

#### Scenario: Admin creates user with invalid email
- **WHEN** the admin submits a user creation form with an email that fails deliverability validation
- **THEN** the request is rejected with a 422 response and the user is NOT created

### Requirement: Automatic welcome on user creation
The system SHALL automatically send a welcome email containing a one-time setup link whenever a user is created with a deliverable email, without requiring any additional flag from the admin.

#### Scenario: User created with username
- **WHEN** a user is created with a deliverable email and a username
- **THEN** the system issues a `setup` token, sends the welcome setup email, and the user can complete the flow to gain access

#### Scenario: User created without username
- **WHEN** a user is created with a deliverable email but no username
- **THEN** the system still issues a `setup` token and sends the welcome setup email; personal storage is created only after the user sets a password AND has a username

### Requirement: Setup password endpoints
The system SHALL expose public endpoints that let a user with a valid `setup` token view a password-setting form and submit a new password.

#### Scenario: User opens setup form with valid token
- **WHEN** the user navigates to the setup link with a valid, unused, unexpired `setup` token
- **THEN** the system renders the password-setting form

#### Scenario: User opens setup form with invalid token
- **WHEN** the user navigates to the setup link with an invalid, expired, or used token
- **THEN** the system redirects to the login screen with a generic error

#### Scenario: User submits a valid password
- **WHEN** the user submits a password that meets the policy (minimum 8 characters) with a valid token
- **THEN** the system consumes the token, materializes the password, activates the user, and redirects to login with a success message

#### Scenario: User submits an invalid password
- **WHEN** the user submits a password shorter than the minimum or with mismatched confirmation
- **THEN** the system re-renders the form with a validation error and does not consume the token

### Requirement: User status lifecycle
The system SHALL track a user status with at least three states: `pending` (created but no password set), `active` (password set and usable), and `disabled` (blocked by admin).

#### Scenario: New user starts pending
- **WHEN** a user is created by the admin
- **THEN** the user starts in `pending` status

#### Scenario: User becomes active after setup
- **WHEN** a user successfully completes the setup password flow
- **THEN** the user's status transitions to `active`

#### Scenario: Login blocked while pending
- **WHEN** a user in `pending` status attempts to log in
- **THEN** the system rejects the login with a clear message indicating that the account must be activated first

### Requirement: Recovery flow with deliverability gate
The system SHALL only send a recovery email when the requested email is both deliverable and associated with an existing user; otherwise it SHALL return a generic success response without revealing any detail.

#### Scenario: Valid email belonging to an existing user
- **WHEN** the user requests password recovery with a deliverable email that matches an existing user in `active` status
- **THEN** the system issues a `reset` token, sends the recovery email, and returns the generic success message

#### Scenario: Invalid or non-existent email
- **WHEN** the user requests password recovery with an email that is not deliverable or does not match any user
- **THEN** the system returns the SAME generic success message and does NOT send any email

#### Scenario: Existing user in pending status
- **WHEN** the user requests password recovery for an account in `pending` status
- **THEN** the system returns the generic success message without sending a recovery email (the user must complete setup instead)

### Requirement: Legacy fallback for users with invalid email
The system SHALL preserve the admin's ability to set a user's password directly through the user-edit endpoint, so users with invalid emails can be rescued without relying on email delivery.

#### Scenario: Admin resets password for a user with invalid email
- **WHEN** an admin updates a user's password via the user-edit endpoint
- **THEN** the password is updated, the user is set to `active`, and no email is required
