## Why

The user profile and configuration panel in the navbar currently returns raw JSON data when accessed. When users click "Mi Perfil" or "Configuración" from the user menu, they see unformatted JSON instead of a proper interface. This creates a poor user experience and doesn't match the visual design of the rest of the Tcloud platform.

## What Changes

- Create a new `profile/show` blade view for displaying user profile information (Mi Perfil)
- Create a new `profile/edit` blade view for editing user settings (Configuración) 
- Modify `UserController::profile()` to return appropriate views instead of JSON
- Add profile page styling consistent with the platform's brand design (glassmorphism, brand colors)
- Add password change functionality in the settings page
- Update navbar links to point to the new profile views

## Capabilities

### New Capabilities
- `user-profile-ui`: User profile display and settings UI
  - `profile-show`: Display user profile information (id, email, role, quota usage)
  - `profile-edit`: Edit user settings including password change

### Modified Capabilities
- (none - no changes to authentication logic or user data model)

## Impact

- **Modified**: `app/app/Http/Controllers/UserController.php` - profile method to return views
- **Created**: `app/resources/views/profile/` directory with show.blade.php and edit.blade.php
- **Modified**: `app/resources/views/layouts/app.blade.php` - navbar links
- **No backend changes**: User data model and validation unchanged
