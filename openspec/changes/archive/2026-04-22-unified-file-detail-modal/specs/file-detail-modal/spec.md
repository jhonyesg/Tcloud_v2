## ADDED Requirements

### Requirement: File Detail Modal displays metadata
The system SHALL display a modal with file metadata including name, formatted size, and upload date when the user clicks the Detail button.

#### Scenario: Modal displays correct metadata
- **WHEN** user clicks Detail button on a file
- **THEN** modal opens showing file name, size (e.g., "2.5 MB"), and upload date in readable format

#### Scenario: Modal shows existing share links
- **WHEN** user opens detail modal for a file with existing share links
- **THEN** modal displays list of all share links for that file with truncated URLs, copy button, and edit option

### Requirement: Detail button replaces Share and Download buttons
The system SHALL replace the separate Share and Download buttons with a single Detail button in the file browser.

#### Scenario: File browser shows Detail button
- **WHEN** user views files in storage browser
- **THEN** each file row displays a single Detail button instead of separate Share and Download buttons

### Requirement: Share link generation from modal
The system SHALL allow users to generate new share links from within the detail modal.

#### Scenario: User generates new share link
- **WHEN** user clicks "Generate Link" button in detail modal
- **THEN** system creates new share link and adds it to the list in the modal