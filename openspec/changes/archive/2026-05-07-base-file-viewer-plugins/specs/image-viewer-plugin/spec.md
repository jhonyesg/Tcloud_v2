## ADDED Requirements

### Requirement: Image viewer displays supported image formats
The plugin SHALL render images inside the provided container element using an HTML5 `<img>` tag, supporting MIME types: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`.

#### Scenario: Load and display a JPEG image
- **WHEN** `image_viewer_pro_init()` is called with a file object where `mime_type` is `image/jpeg`
- **THEN** the plugin renders an `<img>` element inside the container with `src` pointing to `/media/{file.id}/preview`

#### Scenario: Load and display a WebP image
- **WHEN** `image_viewer_pro_init()` is called with a file object where `mime_type` is `image/webp`
- **THEN** the plugin renders an `<img>` element with the correct source URL

### Requirement: Image viewer supports zoom controls
The plugin SHALL provide zoom in, zoom out, and reset zoom controls that scale the image within the container.

#### Scenario: Zoom in increases image scale
- **WHEN** user clicks the zoom in button
- **THEN** the image scale increases by 25% (up to a maximum of 400%)

#### Scenario: Zoom out decreases image scale
- **WHEN** user clicks the zoom out button
- **THEN** the image scale decreases by 25% (down to a minimum of 25%)

#### Scenario: Reset zoom returns to 100%
- **WHEN** user clicks the reset button
- **THEN** the image returns to 100% scale and 0° rotation

### Requirement: Image viewer supports rotation
The plugin SHALL provide a rotate button that rotates the image 90 degrees clockwise.

#### Scenario: Rotate image 90 degrees
- **WHEN** user clicks the rotate button
- **THEN** the image rotates 90° clockwise from its current rotation

### Requirement: Image viewer supports mouse wheel zoom
The plugin SHALL allow zooming the image using the mouse wheel scroll.

#### Scenario: Scroll up zooms in
- **WHEN** user scrolls mouse wheel up over the image
- **THEN** the image zooms in by a small increment

### Requirement: Image viewer displays file metadata
The plugin SHALL display the file name and size in a toolbar above the image.

#### Scenario: Show file name in toolbar
- **WHEN** the plugin initializes with a valid file object
- **THEN** the toolbar displays the file name and formatted file size
