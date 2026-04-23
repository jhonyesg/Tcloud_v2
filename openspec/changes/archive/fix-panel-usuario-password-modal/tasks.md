## 1. Fix Alpine.js Data Scope in Settings Modal

- [ ] 1.1 Locate the settings modal form in `app.blade.php` (around line 323-389)
- [ ] 1.2 Update the form's `x-data` to include `current_password`, `new_password`, and `new_password_confirmation` variables
- [ ] 1.3 Verify the `x-model` bindings on password input fields match the new data variables

## 2. Production Compliance (Optional)

- [ ] 2.1 Review Tailwind CDN script tag location in `<head>` section
- [ ] 2.2 Document if CDN should be replaced with PostCSS setup or if it's intentionally in development

## 3. Verification

- [ ] 3.1 Test password change form submission to ensure no Alpine.js "is not defined" errors
- [ ] 3.2 Verify form submits correctly with all three password fields
