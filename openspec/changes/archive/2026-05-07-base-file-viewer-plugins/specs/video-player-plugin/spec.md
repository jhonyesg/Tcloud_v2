## ADDED Requirements

### Requirement: Video player renders video with native controls
The plugin SHALL render an HTML5 `<video>` element with native browser controls inside the provided container, supporting MIME types: `video/mp4`, `video/webm`, `video/ogg`.

#### Scenario: Play an MP4 video
- **WHEN** `video_player_pro_init()` is called with a file object where `mime_type` is `video/mp4`
- **THEN** the plugin renders a `<video>` element with `controls` attribute and `src` pointing to `/media/{file.id}/preview`

#### Scenario: Video does not autoplay by default
- **WHEN** the plugin initializes
- **THEN** the video element does NOT have the `autoplay` attribute

### Requirement: Video player supports configurable autoplay
The plugin SHALL respect the `autoplay` config option from the plugin configuration.

#### Scenario: Autoplay enabled via config
- **WHEN** `video_player_pro_init()` is called with `config.autoplay` set to `true`
- **THEN** the video element has the `autoplay` attribute

#### Scenario: Autoplay disabled via config
- **WHEN** `video_player_pro_init()` is called with `config.autoplay` set to `false` or undefined
- **THEN** the video element does NOT have the `autoplay` attribute

### Requirement: Video player displays file metadata
The plugin SHALL display the file name below the video player.

#### Scenario: Show file name
- **WHEN** the plugin initializes with a valid file object
- **THEN** the file name is displayed as text below the video element

### Requirement: Video player is responsive
The plugin SHALL constrain the video to a maximum width and height that fits the container.

#### Scenario: Video fits container
- **WHEN** the plugin renders in a container
- **THEN** the video has `max-width: 100%` and `max-height: 70vh` CSS constraints
