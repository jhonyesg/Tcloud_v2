## Context

The Tcloud platform uses Laravel Blade templates with Tailwind CSS and Alpine.js. The main layout (`layouts/app.blade.php`) provides a dark brand-themed navbar with a user dropdown menu containing links to "Configuración" (`/profile`) and "Mi Perfil" (`/auth/me`). Currently, both routes return JSON instead of HTML views.

The UserController's `profile()` method handles both GET (show) and PUT (update) requests but returns JSON responses. The AuthController's `me()` method also returns JSON.

## Goals / Non-Goals

**Goals:**
- Create a visually appealing "Mi Perfil" page displaying user information (email, role, storage quota)
- Create a "Configuración" page for changing password
- Use glassmorphism styling consistent with the login page and platform design
- Maintain existing form validation and security (CSRF protection, password verification)

**Non-Goals:**
- No user registration or account creation changes
- No admin user management features (already exists in admin panel)
- No changes to the underlying user data model
- No email notification on password change

## Decisions

### 1. Two-page approach: show vs edit

**Decision:** Create separate `profile/show.blade.php` (Mi Perfil) and `profile/edit.blade.php` (Configuración) views instead of a single-page tabbed interface.

**Rationale:**
- Simpler implementation with clear separation of concerns
- Users expect distinct pages for viewing vs editing
- Matches the navbar structure where "Configuración" and "Mi Perfil" are separate menu items
- Easier to extend with additional settings in the future

### 2. Profile display page (show.blade.php)

**Decision:** Display user information in a glassmorphism card with the following sections:
- User avatar placeholder with initials
- Email address (read-only display)
- Role badge (Usuario/Administrador)
- Storage quota usage with visual progress bar

**Layout:**
- Centered card with max-width 480px
- Glassmorphism background: `bg-brand-800/90 backdrop-blur-xl`
- Consistent with login page visual style

### 3. Settings page (edit.blade.php)

**Decision:** Include password change form with:
- Current password field (required for verification)
- New password field (min 8 characters)
- Confirm new password field
- CSRF token and method spoofing for PUT request

**Validation:**
- Current password required when changing password
- New password min 8 characters
- New password confirmation must match

### 4. Controller modifications

**Decision:** Modify `UserController::profile()` to check `$request->expectsJson()` and only return JSON for AJAX requests. For browser requests, return views.

**Implementation:**
```php
public function profile(Request $request)
{
    if ($request->expectsJson()) {
        // Existing JSON response for API calls
    }
    
    if ($request->isMethod('get')) {
        return view('profile.show');
    }
    
    return view('profile.edit');
}
```

### 5. Route updates

**Decision:** No route changes needed. The existing `/profile` GET and PUT routes will continue to work. The controller will serve different views based on the HTTP method.

**Alternative considered:** Creating new routes `/profile/show` and `/profile/edit`
- Would require modifying navbar links
- More explicit but adds unnecessary complexity
- Current approach leverages existing routes

### 6. Visual styling consistency

**Decision:** Use existing brand colors and glassmorphism patterns from the login visual upgrade.

Brand color mapping:
- Card background: `bg-brand-800/90`
- Card border: `border-brand-700/50`
- Text primary: `text-white`
- Text secondary: `text-brand-300`
- Input backgrounds: `bg-brand-900/50`
- Focus glow: `focus:ring-brand-500`

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| JSON API breaking for existing AJAX calls | The `expectsJson()` check preserves existing API behavior |
| Password change form UX could be confusing | Clear labels and inline validation messages |
| Progress bar shows hardcoded values | Will use actual user quota data from controller |

## Open Questions

- Should we add a profile picture upload feature?
- Should the storage quota bar show breakdown by file type?
- Should admins be able to view other users' profiles?
