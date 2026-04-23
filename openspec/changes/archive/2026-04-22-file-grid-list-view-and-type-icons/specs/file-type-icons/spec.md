## ADDED Requirements

### Requirement: File type icons
The system SHALL display file type-specific icons based on the file's MIME type.

#### Scenario: Video file displays video icon
- **WHEN** user views a file with MIME type starting with "video/"
- **THEN** a video/play icon is displayed with red/rose color

#### Scenario: Audio file displays audio icon
- **WHEN** user views a file with MIME type starting with "audio/"
- **THEN** a music/audio icon is displayed with purple color

#### Scenario: PDF file displays PDF icon
- **WHEN** user views a file with MIME type "application/pdf"
- **THEN** a PDF icon is displayed with red color

#### Scenario: Office documents display respective icons
- **WHEN** user views a Word document
- **THEN** Word icon is displayed with blue color
- **WHEN** user views an Excel spreadsheet
- **THEN** Excel icon is displayed with green color
- **WHEN** user views a PowerPoint presentation
- **THEN** PowerPoint icon is displayed with orange color

#### Scenario: Image file displays image icon
- **WHEN** user views a file with MIME type starting with "image/"
- **THEN** an image icon is displayed with cyan/teal color

#### Scenario: Archive file displays archive icon
- **WHEN** user views a zip, rar, or 7z file
- **THEN** an archive/compressed icon is displayed with amber color

#### Scenario: Unknown file type shows generic icon
- **WHEN** user views a file that doesn't match any known type
- **THEN** a generic file icon is displayed with gray color