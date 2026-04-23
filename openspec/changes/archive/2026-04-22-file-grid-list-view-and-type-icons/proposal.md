## Why

Currently, the file module only displays files in a grid view with a generic file icon for all file types. Users need:
1. Choice between grid view (current) and list/row view for better file management
2. Visual identification of file types through specific icons (video, audio, PDF, documents)

## What Changes

- Add toggle between grid view and list/row view in file browser
- Implement file type-specific icons:
  - Video files: Play/video icon
  - Audio files: Music/audio icon
  - PDF files: PDF document icon
  - Office documents (Word, Excel, PowerPoint): Respective icons
  - Images: Image icon
  - Archives: Compressed/zip icon
  - Default: Generic file icon

## Capabilities

### Modified Capabilities

- `file-storage-browser`: Add view mode toggle and file type icons

## Impact

- Frontend only: Update file browser template with view modes and type-based icons