## ADDED Requirements

### Requirement: Text editor displays file content as read-only
The plugin SHALL fetch the file content via the media preview endpoint and display it inside a `<pre><code>` block, supporting MIME types: `text/plain`, `text/html`, `text/css`, `text/javascript`, `application/json`.

#### Scenario: Display a plain text file
- **WHEN** `text_editor_basic_init()` is called with a file object where `mime_type` is `text/plain`
- **THEN** the plugin fetches the file content from `/media/{file.id}/preview` and renders it in a `<pre><code>` block

#### Scenario: Display a JSON file
- **WHEN** `text_editor_basic_init()` is called with a file object where `mime_type` is `application/json`
- **THEN** the plugin displays the JSON content with basic syntax highlighting

### Requirement: Text editor applies basic syntax highlighting via CSS
The plugin SHALL apply basic syntax coloring based on the file extension/MIME type using CSS classes.

#### Scenario: HTML file gets syntax highlighting
- **WHEN** a file with `mime_type` `text/html` is loaded
- **THEN** HTML tags are highlighted with a distinct color class

#### Scenario: JSON file gets syntax highlighting
- **WHEN** a file with `mime_type` `application/json` is loaded
- **THEN** JSON keys, strings, numbers, and booleans are highlighted with distinct color classes

### Requirement: Text editor displays file metadata
The plugin SHALL display the file name, type, and size in a toolbar header.

#### Scenario: Show toolbar with file info
- **WHEN** the plugin initializes with a valid file object
- **THEN** a toolbar header displays the file name, MIME type, and formatted file size

### Requirement: Text editor supports line numbers
The plugin SHALL display line numbers alongside the content when `config.lineNumbers` is enabled.

#### Scenario: Line numbers enabled
- **WHEN** `config.lineNumbers` is `true`
- **THEN** each line of content has a corresponding line number displayed on the left

#### Scenario: Line numbers disabled
- **WHEN** `config.lineNumbers` is `false` or undefined
- **THEN** no line numbers are displayed

### Requirement: Text editor handles fetch errors gracefully
The plugin SHALL display an error message if the file content cannot be loaded.

#### Scenario: File fetch fails
- **WHEN** the fetch request to `/media/{file.id}/preview` returns an error
- **THEN** the plugin displays an error message inside the container instead of the content
