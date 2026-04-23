## Why

The current login page lacks visual appeal - it has a solid dark background, a simple white card, and displays hardcoded test credentials publicly. The user wants a premium, modern visual experience with animated particle effects, gradient orbs, and glassmorphism styling that matches contemporary design trends.

## What Changes

- Add animated particle background (250 particles following mouse cursor with inter-particle connections)
- Add 5 animated gradient orbs using brand colors (brand-500, brand-400, brand-300)
- Add subtle grid pattern overlay
- Transform login card from solid white to glassmorphism (brand-800 @ 90% opacity + backdrop blur)
- Update input fields to glass style with brand-500 glow on focus
- Add feature badges strip (Seguro, Rápido, Cloud) with icons
- Remove hardcoded test credentials display
- Maintain existing form POST behavior and CSRF protection

## Capabilities

### New Capabilities
- `login-visual`: UI/UX visual styling for the login page (particles, orbs, glass card, badges)

### Modified Capabilities
- (none - authentication logic unchanged)

## Impact

- **Modified**: `app/resources/views/auth/login.blade.php`
- **No backend changes**: Auth logic remains untouched
- **Dependencies**: FontAwesome already loaded via layouts.app
