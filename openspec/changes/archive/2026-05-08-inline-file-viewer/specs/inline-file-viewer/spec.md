## ADDED Requirements

### Requirement: File click opens inline viewer modal
When a user clicks a file in the grid or list view, the system SHALL open an inline viewer modal if the file's mime type is supported (video, audio, image, PDF). Unsupported types SHALL show a download prompt.

#### Scenario: Clicking a video file opens video player
- **WHEN** user clicks a file with mime type `video/*`
- **THEN** a modal opens showing a `<video>` element with controls, src set to `/media/{file.id}/preview`

#### Scenario: Clicking an audio file opens audio player
- **WHEN** user clicks a file with mime type `audio/*`
- **THEN** a modal opens showing an `<audio>` element with controls, src set to `/media/{file.id}/preview`

#### Scenario: Clicking an image file opens image viewer
- **WHEN** user clicks a file with mime type `image/*`
- **THEN** a modal opens showing an `<img>` element with src set to `/media/{file.id}/preview`

#### Scenario: Clicking a PDF file opens PDF viewer
- **WHEN** user clicks a file with mime type `application/pdf`
- **THEN** a modal opens showing an `<iframe>` with src set to `/media/{file.id}/preview`

#### Scenario: Clicking an unsupported file type shows download prompt
- **WHEN** user clicks a file with a mime type that is not video, audio, image, or PDF
- **THEN** no media viewer opens; instead a download button/link is displayed

### Requirement: Viewer modal can be closed
The system SHALL allow users to close the inline viewer modal, stopping any active media playback.

#### Scenario: Closing modal stops video/audio playback
- **WHEN** user closes the viewer modal while video or audio is playing
- **THEN** media playback stops and the modal is hidden

#### Scenario: Closing modal clears media src
- **WHEN** user closes the viewer modal
- **THEN** the `<video>` and `<audio>` src attributes are cleared to prevent background network requests

### Requirement: Viewer uses authenticated media endpoint
All media previews SHALL use the `/media/{file.id}/preview` route, which requires authentication and supports streaming with range requests.

#### Scenario: Media URL uses correct endpoint
- **WHEN** the viewer opens any file
- **THEN** the src/href used is `/media/{file.id}/preview`, not `/files/{file.id}/preview`

### Requirement: No plugin loading in file viewer
The file viewer in "Mis Archivos" SHALL NOT load any external plugin JS files or query the `file_tool_plugins` table when opening files for preview.

#### Scenario: Opening a file does not make plugin DB queries
- **WHEN** user clicks a supported file type
- **THEN** no request is made to plugin-related API routes (`/api/user/tools`, `/plugins/*`)
