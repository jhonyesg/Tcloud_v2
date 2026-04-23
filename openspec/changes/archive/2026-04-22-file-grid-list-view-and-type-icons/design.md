## Context

The file browser in the storage module currently shows all files in a grid view with a single generic file icon. The user wants to add:
1. List/row view as an alternative to the existing grid view
2. Type-specific icons for visual file identification

## Goals / Non-Goals

**Goals:**
- Add view toggle button (grid/list) in file browser header
- Persist user's view preference in localStorage
- Display type-specific icons based on file MIME type
- Ensure both views show the same file information

**Non-Goals:**
- Changing file metadata display (name, size, date only)
- Adding file preview functionality
- Bulk file operations

## Decisions

### View Toggle
- Add two buttons in file browser header: grid icon and list icon
- Active view highlighted with primary color
- Store preference in `localStorage` key `files_view_mode`

### File Type Icon Mapping
Based on MIME type detection:

| Category | MIME Patterns | Icon | Color |
|----------|---------------|------|-------|
| Video | video/* | Play/video icon | Red/rose |
| Audio | audio/* | Music note icon | Purple |
| PDF | application/pdf | PDF icon | Red |
| Word | application/vnd.openxmlformats-officedocument.wordprocessingml.document | Word icon | Blue |
| Excel | application/vnd.openxmlformats-officedocument.spreadsheetml.sheet | Excel icon | Green |
| PowerPoint | application/vnd.openxmlformats-officedocument.presentationml.presentation | PowerPoint icon | Orange |
| Image | image/* | Image icon | Cyan/teal |
| Archive | application/zip, application/x-rar-compressed, application/x-7z-compressed | Archive icon | Amber |
| Code | text/*, application/json, application/javascript | Code icon | Slate |
| Default | * | Generic file icon | Gray |

### List View Layout
- Horizontal row per file/folder
- Columns: Icon | Name | Size (files only) | Modified Date | Actions
- Clickable row for navigation (folders)
- Hover state with background highlight

## Risks / Trade-offs

- [Risk] Many file types may not have clear MIME type → Mitigation: Use file extension as fallback
- [Risk] Icons may look inconsistent if mixing icon libraries → Mitigation: Use consistent Heroicons or FontAwesome set