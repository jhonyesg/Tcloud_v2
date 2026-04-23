## 1. Add View Mode Toggle

- [x] 1.1 Add state variable `viewMode` ('grid' | 'list') in Alpine.js data
- [x] 1.2 Add toggle buttons in file browser header (grid icon, list icon)
- [x] 1.3 Implement localStorage persistence for view preference
- [x] 1.4 Style active view button with primary color

## 2. Implement List View Layout

- [x] 2.1 Create list view container with table structure
- [x] 2.2 Add columns: Icon, Name, Size, Modified Date, Actions
- [x] 2.3 Style rows with hover effects and clickable navigation
- [x] 2.4 Ensure folders show folder icon and navigate on click

## 3. Add File Type Icon Helper Function

- [x] 3.1 Create `getFileIcon(file)` function in Alpine.js
- [x] 3.2 Map MIME types to icons and colors:
  - video/* → Play icon (red)
  - audio/* → Music icon (purple)
  - application/pdf → PDF icon (red)
  - Word/Excel/PowerPoint → Respective icons
  - image/* → Image icon (cyan)
  - archives (zip/rar/7z) → Archive icon (amber)
  - default → Generic file icon (gray)
- [x] 3.3 Use file extension as fallback if MIME type not available

## 4. Update Grid View Icons

- [x] 4.1 Replace generic file icon in grid view with type-specific icons
- [x] 4.2 Apply appropriate color based on file type
- [x] 4.3 Ensure folder icon remains unchanged

## 5. Testing

- [x] 5.1 Test view toggle persistence across page reloads
- [x] 5.2 Test all file type icons render correctly
- [x] 5.3 Test list view click navigation for folders