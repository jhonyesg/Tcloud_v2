## ADDED Requirements

### Requirement: View mode toggle button
The system SHALL display a toggle button to switch between grid view and list view in the file browser header.

#### Scenario: Toggle button visible
- **WHEN** user is in file browser view
- **THEN** toggle buttons for grid/list view are visible in the header

#### Scenario: Grid view active by default
- **WHEN** user opens file browser with no saved preference
- **THEN** grid view is displayed as default

#### Scenario: User switches to list view
- **WHEN** user clicks list view button
- **THEN** view changes to list/row format and preference is saved

#### Scenario: Preference persisted
- **WHEN** user switches view mode
- **THEN** preference is saved to localStorage and restored on page reload