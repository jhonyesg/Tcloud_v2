## ADDED Requirements

### Requirement: Stat tiles on the trash view

The `/papelera` view MUST display four stat tiles above the listing. The tiles MUST be computed server-side via `PapeleraService::statsFor(userId)` and MUST include: `Total` (count of all trashed items owned by the user), `Por expirar pronto` (count with `days_remaining <= urgent_threshold_days`, default 3), `Espacio a liberar` (sum of `file.size` for items that will be hard-deleted at next purge, excluding linked items that won't be purged), and `Próxima purga` (next `trash:purge` cron run, computed as 03:17 local of the next day if today's is past, otherwise today). Each tile MUST show the big number first, then a small label below. No eyebrow text, no ALL CAPS labels, no decorative borders distinct from the rest of the page.

#### Scenario: User with non-empty trash loads the page
- **WHEN** the user loads `/papelera` and has at least one trashed item
- **THEN** the four stat tiles render with non-zero values for at least `Total`
- **AND** the values match `PapeleraService::statsFor()` output exactly

#### Scenario: User with empty trash loads the page
- **WHEN** the user loads `/papelera` and has zero trashed items
- **THEN** the stat tiles render with zeros (or "—" for size/date) without crashing

### Requirement: Per-row progress bar visualizing retention lifecycle

Each row in the trash listing MUST include a progress bar whose width is `(days_remaining / retention_days) * 100`. The bar's color MUST shift based on the fraction remaining: `> 30%` uses `bg-brand-500` (calm), `> 10% and ≤ 30%` uses `bg-amber-500` (attention), `≤ 10%` uses `bg-red-500` (urgent). This bar IS the "días restantes" data — it replaces or augments the plain number. No decorative double-rendering.

#### Scenario: Item just trashed (full bar)
- **WHEN** an item is freshly trashed (today)
- **THEN** its progress bar is at ~100% width in `bg-brand-500`

#### Scenario: Item close to purge (red bar)
- **WHEN** an item has ≤ 10% of retention remaining (1-2 days out of 15)
- **THEN** its progress bar is short and `bg-red-500`

### Requirement: State filter chips on the trash view

The `/papelera` view MUST display three filter chips: `Todos`, `Por expirar (<3d)`, `Críticos (<1d)`. The active filter MUST be visually distinct. Filtering MUST be client-side only (Alpine state, no server reload). The default filter on page load is `Todos`.

#### Scenario: Filter chip narrows the visible items
- **WHEN** the user clicks the `Por expirar` chip
- **THEN** the table renders only items with `days_remaining < 3`
- **AND** the visible count badge on the chip stays in sync

### Requirement: Urgent banner when items are near expiration

When `stats.urgent > 0`, a banner MUST appear at the top of the listing area with amber background and an exclamation triangle icon. The banner text MUST link to the urgent filter chip via an inline click handler.

#### Scenario: Items exist with urgent threshold days remaining
- **WHEN** `stats.urgent > 0`
- **THEN** a banner appears at the top of the listing area
- **AND** the banner text links to the urgent filter

#### Scenario: No urgent items
- **WHEN** `stats.urgent == 0`
- **THEN** the urgent banner is not rendered

### Requirement: Responsive card layout on small viewports

When the viewport width is below Tailwind's `sm:` breakpoint (640px), each row MUST render as a stacked card. When the viewport is at or above `sm:`, the table layout MUST be used instead.

#### Scenario: Listing on viewports below sm: breakpoint
- **WHEN** the viewport width is below 640px
- **THEN** rows render as stacked cards (one item per card)
- **AND** the desktop table is hidden

#### Scenario: Listing on viewports at or above sm: breakpoint
- **WHEN** the viewport width is at or above 640px
- **THEN** the table renders normally
- **AND** the cards are hidden
