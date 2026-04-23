## 1. Create Profile Views Directory

- [x] 1.1 Create `app/resources/views/profile/` directory

## 2. Create Profile Show View (Mi Perfil)

- [x] 2.1 Create `app/resources/views/profile/show.blade.php` with glassmorphism card layout
- [x] 2.2 Add user avatar section with initials in brand-500 circle
- [x] 2.3 Add email display field (read-only)
- [x] 2.4 Add role badge (Administrador/Usuario) with conditional styling
- [x] 2.5 Add storage quota section with progress bar showing used/total bytes
- [x] 2.6 Style card with `bg-brand-800/90 backdrop-blur-xl border border-brand-700/50 rounded-2xl`

## 3. Create Profile Edit View (Configuración)

- [x] 3.1 Create `app/resources/views/profile/edit.blade.php` with glassmorphism card layout
- [x] 3.2 Add password change form with CSRF token and method spoofing for PUT
- [x] 3.3 Add current password input field
- [x] 3.4 Add new password input field (min 8 chars validation hint)
- [x] 3.5 Add confirm password input field
- [x] 3.6 Add submit button with loading state
- [x] 3.7 Add validation error display areas for each field
- [x] 3.8 Add success message display area

## 4. Modify UserController

- [x] 4.1 Update `UserController::profile()` to detect view vs API request
- [x] 4.2 Return `view('profile.show')` for GET requests from browser
- [x] 4.3 Return `view('profile.edit')` for PUT requests from browser
- [x] 4.4 Keep existing JSON response for AJAX/API requests (`expectsJson()`)
- [x] 4.5 Ensure password change logic works for both browser and API forms

## 5. Testing

- [ ] 5.1 Test clicking "Mi Perfil" opens modal instead of navigating
- [ ] 5.2 Test clicking "Configuración" opens modal instead of navigating
- [ ] 5.3 Test password change with correct current password succeeds
- [ ] 5.4 Test password change with incorrect current password shows error
- [ ] 5.5 Test password change with mismatched new passwords shows error
- [ ] 5.6 Test modal closes when clicking outside or pressing Escape
- [ ] 5.7 Verify glassmorphism styling matches login page design
