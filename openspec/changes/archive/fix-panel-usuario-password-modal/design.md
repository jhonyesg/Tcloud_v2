## Context

The panel usuario in `app.blade.php` contains two modals: Profile Modal (`showProfileModal`) and Settings Modal (`showSettingsModal`). The Settings Modal includes a password change form with Alpine.js `x-model` bindings, but the data variables (`current_password`, `new_password`, `new_password_confirmation`) are not defined in the Alpine.js data scope.

**Current State:**
- Parent `x-data` (line 53): `x-data="{ sidebarOpen: true, userMenuOpen: false, showProfileModal: false, showSettingsModal: false }"`
- Form `x-data` (inside Settings Modal): `x-data="{ loading: false, error: '', success: '' }"` - missing password fields

## Goals / Non-Goals

**Goals:**
- Fix Alpine.js data scope so password form fields are properly bound
- Users can successfully change their password
- Remove Tailwind CDN reference for production compliance

**Non-Goals:**
- Redesign the modal UI
- Add new password validation features beyond existing rules
- Change the backend password change logic

## Decisions

1. **Add password fields to the form-level Alpine.js data object**
   - Change: `x-data="{ loading: false, error: '', success: '' }"` → `x-data="{ loading: false, error: '', success: '', current_password: '', new_password: '', new_password_confirmation: '' }"`

2. **Remove Tailwind CDN script tag**
   - The CDN script in `<head>` should be replaced with proper PostCSS/CLI setup for production

## Risks / Trade-offs

- [Low Risk] The modal form already has proper CSRF and method spoofing, so the backend should work once Alpine data is fixed
- [Note] The form uses PUT method spoofing which Laravel handles correctly

## Open Questions

None - the fix is straightforward scope correction.
