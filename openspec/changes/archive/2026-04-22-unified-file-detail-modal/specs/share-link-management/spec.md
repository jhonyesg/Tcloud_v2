## ADDED Requirements

### Requirement: Share link list displays all links for a file
The system SHALL list all share links associated with a file, showing truncated URL, creation date, and action buttons.

#### Scenario: Share link list shows truncated URL
- **WHEN** share link list is displayed in detail modal
- **THEN** each link shows a truncated URL (first 30 characters with ellipsis) and full URL on hover

#### Scenario: Share link list shows action buttons
- **WHEN** share links are displayed
- **THEN** each link has a Copy button and Edit button visible

### Requirement: Copy share link to clipboard
The system SHALL allow users to copy a share link URL to clipboard with a single click.

#### Scenario: User copies share link
- **WHEN** user clicks Copy button on a share link
- **THEN** full URL is copied to clipboard and success feedback is shown

### Requirement: Edit share link inline
The system SHALL allow users to edit share links inline within the modal without navigating away.

#### Scenario: User edits share link
- **WHEN** user clicks Edit button on a share link
- **THEN** URL field becomes editable with Save/Cancel options

#### Scenario: User saves edited share link
- **WHEN** user modifies URL and clicks Save
- **THEN** link is updated and modal remains open with updated list