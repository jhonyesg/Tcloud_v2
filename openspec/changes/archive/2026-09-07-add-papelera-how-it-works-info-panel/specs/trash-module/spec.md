## ADDED Requirements

### Requirement: How-it-works info panel on the trash view

The `/papelera` view MUST expose a collapsible accordion titled "¿Cómo funciona la papelera?" placed between the page header and the listing (or empty state). The accordion MUST be collapsed by default and MUST be togglable via a button click. When expanded, the panel MUST explain, in plain Spanish, four blocks: (1) what happens internally when a user deletes a file (soft-trash flags, no disk move, no row duplication, child recursion for folders); (2) the retention purge lifecycle including the `trash:purge` daily cron, the configurable retention window, the mass-delete guardrail, and the linked-item protection; (3) the difference between Restore and Hard-delete, including the `-restored-<timestamp>` suffix on name collision and the blocked-delete-when-linked behavior; (4) the quota and public-share implications (storage accounting stays until hard-delete, public share links return 410 Gone while the file is trashed). The visual pattern MUST match the existing "¿Cómo funciona la API del transcriptor?" accordion at `app/resources/views/ia/api-transcriptor/index.blade.php:60-111` (white card, brand-500 `fa-circle-info` icon, chevron rotation on toggle, two-column grid on `md:` breakpoint, `font-mono bg-slate-100` for technical terms).

#### Scenario: User opens the help panel
- **WHEN** an authenticated user clicks the "¿Cómo funciona la papelera?" toggle on `/papelera`
- **THEN** the panel expands and shows the four explanation blocks with technical terms rendered in monospace

#### Scenario: User collapses the help panel
- **WHEN** the user clicks the toggle again while the panel is expanded
- **THEN** the panel collapses back to its header-only state and the chevron rotates back

#### Scenario: Page loads with the panel collapsed
- **WHEN** an authenticated user navigates to `/papelera` for the first time in a session
- **THEN** the help panel is rendered collapsed and does not occupy visual space beyond its header
