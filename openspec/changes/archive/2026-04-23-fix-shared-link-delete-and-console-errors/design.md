## Context

The shared link view (`shares/public.blade.php`) has two issues:

1. **Delete file issue**: When deleting a file via shared link, the `refreshFolderContents()` function is called but files reappear. This suggests the folder contents are being re-fetched but the UI isn't properly updating, OR the deletion API response isn't being handled correctly.

2. **Alpine.js PDF fullscreen error**: The inline Alpine expression on the PDF fullscreen button has malformed JavaScript with missing closing braces in the else-if branch.

3. **Tailwind CDN warning**: Using `cdn.tailwindcss.com` in production is not recommended by Tailwind.

## Goals / Non-Goals

**Goals:**
- Fix immediate file removal from shared folder UI after delete
- Fix Alpine.js syntax error causing console errors
- Remove Tailwind CDN dependency

**Non-Goals:**
- Not implementing a full CDN migration (just removing the problematic CDN)
- Not changing any backend API behavior

## Decisions

1. **Delete fix approach**: The current `refreshFolderContents()` likely triggers a re-fetch. Instead, we should remove the file from the local `folderContents` array immediately upon successful API response before/after the refresh call.

2. **PDF fullscreen fix**: The inline expression has unbalanced braces. Split into a proper method in the Alpine component to avoid inline complexity.

3. **Tailwind CDN**: Remove from `app.blade.php` - the application should use the compiled Tailwind CSS from build process.

## Risks / Trade-offs

[Minor] - Removing Tailwind CDN assumes proper build process exists. If build isn't run, UI styling will be broken. → Ensure `npm run dev` or `npm run build` is used in development/production.

## Migration Plan

1. Fix JavaScript in `public.blade.php` Alpine component
2. Test delete functionality
3. Remove Tailwind CDN reference
4. Verify all UI components render correctly