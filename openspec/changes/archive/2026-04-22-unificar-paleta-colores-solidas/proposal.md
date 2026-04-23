## Why

The platform currently uses gradient backgrounds in several views (shares pages, icons), creating visual inconsistency. The user wants uniform solid colors based on a cohesive palette starting with `#03153C`.

## What Changes

- Replace gradient backgrounds with solid colors from a defined palette
- Define a complete color palette for the entire platform
- Apply solid colors consistently across all views

## Capabilities

### New Capabilities
- `unified-color-palette`: Define and apply a cohesive color palette across all platform views

### Modified Capabilities
- (none - styling change only, no spec-level behavior changes)

## Impact

- **Affected Views**: 
  - `shares/public.blade.php` - gradient background and header
  - `shares/public-password.blade.php` - gradient background
  - `shares/public-not-found.blade.php` - gradient background
  - `shares/public-expired.blade.php` - gradient background
  - `files/index.blade.php` - gradient icon backgrounds
- **Styling System**: Tailwind CSS via Laravel Vite asset pipeline