## ADDED Requirements

### Requirement: Unified color palette
The platform SHALL use a consistent solid color palette based on #03153C as the primary dark color, replacing all gradient backgrounds.

#### Scenario: Public shares pages use solid background
- **WHEN** user visits any public share page (public.blade.php, public-password.blade.php, public-not-found.blade.php, public-expired.blade.php)
- **THEN** the page background SHALL be solid #03153C (`bg-[#03153C]`) instead of gradient

#### Scenario: Public shares header uses solid accent color
- **WHEN** user views the header area of a public share page
- **THEN** the header background SHALL be solid #0A1F4D (`bg-[#0A1F4D]`) instead of gradient

#### Scenario: File icons use consistent solid color
- **WHEN** user views file icons with gradient backgrounds in files/index.blade.php
- **THEN** the icon background SHALL be solid #2451B8 (`bg-[#2451B8]`) instead of gradient

### Requirement: Color token definitions
The platform SHALL define the following color tokens for the unified palette:

| Token | Hex | Usage |
|-------|-----|-------|
| brand-900 | #03153C | Primary dark (page backgrounds) |
| brand-800 | #0A1F4D | Secondary dark (headers, cards) |
| brand-700 | #0F2966 | Accent backgrounds |
| brand-600 | #1A3D8F | Interactive elements |
| brand-500 | #2451B8 | Accent elements (icons, buttons) |
| brand-400 | #4A6FD9 | Hover/active states |

#### Scenario: Colors applied via Tailwind classes
- **WHEN** implementing color changes
- **THEN** colors SHALL be applied using inline hex values via `bg-[#XXXXXX]` Tailwind syntax