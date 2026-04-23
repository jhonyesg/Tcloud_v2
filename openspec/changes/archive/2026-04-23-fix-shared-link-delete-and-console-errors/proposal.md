## Why

When sharing files via public links, users encounter two critical issues that affect usability: (1) deleting files from shared links doesn't work correctly - files reappear until user navigates to view a file, and (2) console errors from CDN usage (Tailwind CSS from cdn.tailwindcss.com) and Alpine.js syntax errors in the PDF fullscreen toggle.

## What Changes

- Fix delete functionality in shared link views so files are immediately removed from the UI
- Fix Alpine.js syntax error in PDF fullscreen button that causes console errors
- Replace Tailwind CSS CDN with proper PostCSS integration
- Fix malformed JavaScript in the `else if` branch of PDF fullscreen toggle

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `sharing`: The delete file behavior in public share view needs to properly refresh UI state

## Impact

- `app/resources/views/shares/public.blade.php` - Contains delete function and PDF fullscreen toggle
- `app/resources/views/layouts/app.blade.php` - Tailwind CDN reference
- `package.json` / PostCSS config - Need Tailwind PostCSS setup for production