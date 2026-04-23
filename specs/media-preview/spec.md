## ADDED Requirements

### Requirement: Image Preview
The system SHALL provide inline preview for image files with a modern viewer.

#### Scenario: View JPEG image
- **WHEN** user requests preview for image/jpeg file
- **THEN** system returns image with Content-Type: image/jpeg and inline Content-Disposition

#### Scenario: View PNG image
- **WHEN** user requests preview for image/png file
- **THEN** system returns image with Content-Type: image/png

#### Scenario: View GIF image
- **WHEN** user requests preview for image/gif file
- **THEN** system returns animated GIF

#### Scenario: Modern image viewer
- **WHEN** user views image in browser
- **THEN** system provides modern viewer with zoom, pan, rotate controls

### Requirement: PDF Preview
The system SHALL provide inline preview for PDF documents.

#### Scenario: View PDF document
- **WHEN** user requests preview for application/pdf file
- **THEN** system returns PDF with inline Content-Disposition using PDF.js viewer

#### Scenario: PDF viewer controls
- **WHEN** user views PDF
- **THEN** viewer supports page navigation, zoom, search functionality

### Requirement: Audio Preview
The system SHALL provide audio playback for MP3 and MP4A files.

#### Scenario: Play MP3 audio
- **WHEN** user requests preview for audio/mpeg file
- **THEN** system streams audio with HTML5 audio player

#### Scenario: Play MP4A audio
- **WHEN** user requests preview for audio/mp4a file
- **THEN** system streams audio with HTML5 audio player

#### Scenario: Audio player controls
- **WHEN** user plays audio
- **THEN** player provides play/pause, seek, volume controls, and progress bar

### Requirement: Video Preview
The system SHALL provide video playback for MP4 files with adaptive streaming.

#### Scenario: Play MP4 video
- **WHEN** user requests preview for video/mp4 file
- **THEN** system streams video with HTML5 video player

#### Scenario: Adaptive streaming based on device
- **WHEN** user plays video
- **THEN** system detects device capabilities and serves appropriate quality stream

#### Scenario: Video player controls
- **WHEN** user views video
- **THEN** player provides play/pause, seek, volume, fullscreen, quality selection

### Requirement: Redis Caching for Media
The system SHALL use Redis to cache media metadata for fast retrieval.

#### Scenario: Cache thumbnails
- **WHEN** image is uploaded and preview is requested
- **THEN** thumbnail is generated and cached in Redis with key `file:thumb:{file_id}` for 1 day

#### Scenario: Cache share metadata
- **WHEN** share link is accessed
- **THEN** share metadata is cached in Redis with key `share:meta:{token}` for 1 hour

#### Scenario: Cache video streaming metadata
- **WHEN** video playback is initiated
- **THEN** segment metadata is cached in Redis with key `media:stream:{file_id}` for 1 hour

#### Scenario: Invalidate cache on file delete
- **WHEN** file is deleted
- **THEN** related cache entries are removed from Redis