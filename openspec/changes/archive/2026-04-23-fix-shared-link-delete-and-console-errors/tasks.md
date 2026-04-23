## 1. Fix Delete Functionality in Shared Link View

- [ ] 1.1 Review current refreshFolderContents() implementation in public.blade.php
- [ ] 1.2 Fix delete handler to properly remove file from local state immediately
- [ ] 1.3 Test delete functionality with shared link

## 2. Fix Alpine.js PDF Fullscreen Error

- [ ] 2.1 Extract PDF fullscreen toggle into proper component method
- [ ] 2.2 Remove malformed inline JavaScript from the button
- [ ] 2.3 Verify PDF fullscreen works without console errors

## 3. Remove Tailwind CSS CDN Dependency

- [ ] 3.1 Remove CDN script tag from layouts/app.blade.php
- [ ] 3.2 Verify Tailwind styles are properly compiled via PostCSS
- [ ] 3.3 Check that all UI components render correctly