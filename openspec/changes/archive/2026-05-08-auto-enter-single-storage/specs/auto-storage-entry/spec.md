## ADDED Requirements

### Requirement: Single storage auto-entry
When a user has exactly one assigned storage, the system SHALL automatically enter that storage and display its files, without showing the storage selector.

#### Scenario: User with one storage loads files module
- **WHEN** user navigates to the files module
- **AND** user has exactly one assigned storage
- **THEN** system SHALL immediately display the file browser for that storage
- **AND** system SHALL NOT display the storage selector

#### Scenario: User with zero storages loads files module
- **WHEN** user navigates to the files module
- **AND** user has zero assigned storages
- **THEN** system SHALL display the storage selector
- **AND** system SHALL show "No tienes storages asignados" message

#### Scenario: User with multiple storages loads files module
- **WHEN** user navigates to the files module
- **AND** user has two or more assigned storages
- **THEN** system SHALL display the storage selector
- **AND** user SHALL manually select which storage to enter

#### Scenario: Navigation state restoration takes precedence
- **WHEN** user has saved navigation state (previously selected storage)
- **AND** user returns to the files module
- **THEN** system SHALL restore the saved storage and folder
- **AND** auto-entry SHALL NOT apply (saved state indicates intentional navigation)