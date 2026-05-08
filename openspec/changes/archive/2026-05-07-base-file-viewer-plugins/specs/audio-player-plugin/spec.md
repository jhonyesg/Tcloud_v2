## ADDED Requirements

### Requirement: Audio player renders audio with native controls
The plugin SHALL render an HTML5 `<audio>` element with native browser controls inside the provided container, supporting MIME types: `audio/mpeg`, `audio/wav`, `audio/ogg`, `audio/flac`, `audio/mp4`.

#### Scenario: Play an MP3 file
- **WHEN** `audio_player_pro_init()` is called with a file object where `mime_type` is `audio/mpeg`
- **THEN** the plugin renders an `<audio>` element with `controls` attribute and `src` pointing to `/media/{file.id}/preview`

#### Scenario: Audio does not autoplay
- **WHEN** the plugin initializes
- **THEN** the audio element does NOT have the `autoplay` attribute

### Requirement: Audio player displays file metadata
The plugin SHALL display the file name and formatted file size in a visual header above the player controls.

#### Scenario: Show file name and size
- **WHEN** the plugin initializes with a valid file object
- **THEN** the header displays the file name and the formatted file size

### Requirement: Audio player shows visual icon
The plugin SHALL display a music/audio icon as a visual indicator in the player area.

#### Scenario: Audio icon displayed
- **WHEN** the plugin renders
- **THEN** an SVG audio/music icon is displayed above the file name

### Requirement: Audio player is styled and centered
The plugin SHALL display the player area centered within the container with a light background.

#### Scenario: Centered layout
- **WHEN** the plugin renders
- **THEN** the player area is flex-centered horizontally and vertically with padding and a light gray background
