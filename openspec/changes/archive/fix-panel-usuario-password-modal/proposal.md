## Why

The user panel modal for password changes in the panel usuario has critical Alpine.js errors preventing users from changing their passwords. The form fields use `x-model` bindings for `current_password`, `new_password`, and `new_password_confirmation`, but these variables are not defined in the Alpine.js data scope, causing "is not defined" errors.

## What Changes

- Fix Alpine.js data scope in the settings modal to include password form fields (`current_password`, `new_password`, `new_password_confirmation`)
- Ensure proper data binding between form inputs and Alpine.js data for password validation
- Remove Tailwind CSS CDN reference for production compliance

## Capabilities

### New Capabilities
- `panel-usuario-password-modal`: User panel password change modal with proper Alpine.js data binding

### Modified Capabilities
- None - this is a bug fix to existing functionality

## Impact

- **Affected files**: `app/resources/views/layouts/app.blade.php` (settings modal)
- **User experience**: Users can now successfully change their password through the panel usuario
- **Production readiness**: Removes CDN Tailwind reference (should use PostCSS or CLI)
