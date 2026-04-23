## ADDED Requirements

### Requirement: Profile display page shows user information
The system SHALL display a "Mi Perfil" page at `/profile` (GET request) showing the authenticated user's profile information including email, role, and storage quota usage.

#### Scenario: Authenticated user views profile page
- **WHEN** authenticated user navigates to `/profile` via GET request
- **THEN** system displays a profile page with user email, role badge, and storage quota information
- **AND** page uses glassmorphism styling consistent with platform design

#### Scenario: Profile page shows correct user data
- **WHEN** authenticated user views their profile
- **THEN** system displays their email address from the database
- **AND** system displays their role as either "Administrador" (admin) or "Usuario" (user)
- **AND** system displays storage quota as used/total bytes with percentage

#### Scenario: Unauthenticated access redirects to login
- **WHEN** unauthenticated user attempts to access `/profile`
- **THEN** system redirects to `/login`

### Requirement: Profile settings page allows password change
The system SHALL display a "Configuración" settings page at `/profile` (PUT request) allowing users to change their password with proper verification.

#### Scenario: User changes password successfully
- **WHEN** user fills password change form with correct current password and matching new passwords
- **THEN** system updates the user's password hash in the database
- **AND** system displays a success message

#### Scenario: Password change fails with incorrect current password
- **WHEN** user enters incorrect current password
- **THEN** system returns validation error "Current password is incorrect"
- **AND** password remains unchanged

#### Scenario: Password change fails with mismatched new passwords
- **WHEN** user enters new password and confirmation that do not match
- **THEN** system returns validation error about password confirmation

#### Scenario: Password change fails with short password
- **WHEN** user enters new password with less than 8 characters
- **THEN** system returns validation error "The new password must be at least 8 characters"

### Requirement: Profile pages use consistent visual design
The profile show and edit pages SHALL use glassmorphism styling matching the Tcloud platform design language.

#### Scenario: Profile card uses glassmorphism styling
- **WHEN** user views profile page
- **THEN** card uses `bg-brand-800/90 backdrop-blur-xl` background
- **AND** card has `border border-brand-700/50` border
- **AND** card has `rounded-2xl` border radius

#### Scenario: Form inputs use consistent styling
- **WHEN** user views profile edit page
- **THEN** inputs use `bg-brand-900/50` background
- **AND** inputs show `focus:ring-brand-500` focus state
- **AND** labels use `text-white` for primary and `text-brand-300` for secondary

### Requirement: API requests return JSON response
The `/profile` endpoint SHALL return JSON when the request includes `Accept: application/json` header or is an AJAX request.

#### Scenario: API request for profile data
- **WHEN** API client sends GET request to `/profile` with `Accept: application/json`
- **THEN** system returns JSON with id, email, role, personal_quota_bytes, personal_used_bytes

#### Scenario: API password update request
- **WHEN** API client sends PUT request to `/profile` with valid password change data
- **THEN** system returns JSON with updated user data
