## Context

Currently, the file module in storage browser has separate "Share" and "Download" buttons for each file. The user wants to consolidate these into a single "Detail" action that opens a modal showing file metadata and share link management.

## Goals / Non-Goals

**Goals:**
- Unified Detail button replacing Share/Download buttons
- Modal displays file name, size, upload date
- Generate share links from within the modal
- List all generated share links in the modal
- Copy and edit share links from the modal

**Non-Goals:**
- Changing download functionality (download remains accessible via the modal)
- Backend share link storage implementation (assuming API already exists)
- Bulk share link operations

## Decisions

1. **Single Detail Button**: Replace Share/Download buttons with one "Detail" button that opens the modal. This simplifies the UI and consolidates file actions.

2. **Modal-Based Design**: Detail modal contains two sections:
   - File metadata section (read-only): name, size (formatted), upload date
   - Share links section: list of links with copy/edit actions and "Generate new link" button

3. **Share Link List**: Each share link displays:
   - Truncated URL for identification
   - Copy button
   - Edit button (opens inline edit mode)
   - Creation date

4. **Inline Link Editing**: Share links can be edited inline without navigating away from the modal.

## Risks / Trade-offs

- [Risk] Share API may not support listing links by file ID → Mitigation: Check API capabilities first, adjust spec if needed
- [Risk] Long share link URLs may break layout → Mitigation: Truncate display with tooltip, copy button always visible