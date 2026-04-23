## Why

The current file module has separate "Share" and "Download" buttons in the storage browser. Users need a unified way to view file details and manage share links in one place, improving workflow efficiency and providing better visibility into file sharing history.

## What Changes

- Replace separate "Share" and "Download" buttons with a unified "Detail" button/action
- Create a detail modal that displays file metadata (name, size, upload date)
- Add share link generation functionality within the detail modal
- Display a list of generated share links in the modal
- Allow users to copy and edit share links directly from the modal

## Capabilities

### New Capabilities

- `file-detail-modal`: Unified modal component for viewing file metadata and managing share links
- `share-link-management`: Component for generating, listing, copying, and editing share links within the file detail modal

### Modified Capabilities

- `file-storage-browser`: Update file listing to show single "Detail" action instead of separate Share/Download buttons

## Impact

- Frontend: New DetailModal component, ShareLinkList component, updated file browser actions
- Storage module UI changes in file browsing interface