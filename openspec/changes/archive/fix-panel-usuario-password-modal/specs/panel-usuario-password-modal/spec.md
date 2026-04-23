## ADDED Requirements

### Requirement: Panel usuario password change modal
The panel usuario settings modal SHALL provide a functional password change form with properly bound Alpine.js data variables.

#### Scenario: User opens settings modal
- **WHEN** user clicks "Configuración" in the panel usuario dropdown
- **THEN** the settings modal opens with password change form fields: current password, new password, and confirmation

#### Scenario: Password fields are bound to Alpine data
- **WHEN** the settings modal is displayed
- **THEN** the form's Alpine.js data scope SHALL include `current_password`, `new_password`, and `new_password_confirmation` variables
- **AND** each input field SHALL be bound via `x-model` to its corresponding data variable

#### Scenario: User submits password change with valid data
- **WHEN** user fills all password fields with valid data and clicks "Actualizar"
- **THEN** the form SHALL submit with `current_password`, `new_password`, and `new_password_confirmation` fields
- **AND** the user SHALL receive a success message upon completion

#### Scenario: User submits password change with invalid current password
- **WHEN** user enters an incorrect current password and submits
- **THEN** the system SHALL return an error message indicating the current password is incorrect

#### Scenario: User submits password change with mismatched new passwords
- **WHEN** user enters a new password that does not match the confirmation
- **THEN** the system SHALL return an error message indicating the passwords do not match
