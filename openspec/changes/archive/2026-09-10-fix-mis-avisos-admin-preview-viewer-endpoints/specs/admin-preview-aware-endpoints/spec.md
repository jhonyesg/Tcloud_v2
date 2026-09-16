## Purpose

Declares the contract between the routes that participate in admin preview
and the apiFetch wrapper that propagates `?as_user=X` to those routes, so
the unified transcript viewer works when an administrator previews a
client's Mis Avisos data.

## ADDED Requirements

### Requirement: Preview-aware URL prefix list is single-sourced

The system SHALL expose a public constant
`App\Http\Middleware\AdminPreviewSwap::PREVIEW_AWARE_PREFIXES` listing
every URL prefix whose endpoints participate in admin preview when
`?as_user=X` is present. The constant SHALL be the single source of
truth used by both the routes group placement and the apiFetch wrapper
that auto-appends `?as_user=X`.

#### Scenario: Adding a new preview-aware endpoint

- **WHEN** a developer needs a new endpoint to honor `?as_user=X` from
  the admin preview context
- **THEN** the developer SHALL add the entry's URL prefix to
  `PREVIEW_AWARE_PREFIXES` AND include the route in the
  `['auth', 'misavisos', AdminPreviewSwap::class]` middleware group AND
  document the addition in the change's tasks

#### Scenario: Initial prefix list

- **WHEN** the constant is read at boot
- **THEN** it SHALL contain at minimum the prefixes `mis-avisos`, `files`
  and `media`

### Requirement: Layout exposes the prefix list to client-side wrappers

The system SHALL inject `window.__adminPreviewAwarePrefixes` as a JSON
array from the authenticated layout so any client-side `apiFetch`
wrapper can consume it without duplicating the list.

#### Scenario: Layout renders with impersonation active

- **WHEN** the authenticated layout is rendered for an admin request
  that includes `?as_user=X`
- **THEN** `window.__adminPreviewAwarePrefixes` SHALL equal
  `AdminPreviewSwap::PREVIEW_AWARE_PREFIXES` serialized as JSON

#### Scenario: Layout renders without impersonation

- **WHEN** the authenticated layout is rendered for any request that
  does not include `?as_user=X`
- **THEN** `window.__adminPreviewAwarePrefixes` SHALL still be exposed
  (the variable is always present) so that any page may opt into the
  apiFetch wrapper without re-injecting

### Requirement: apiFetch wrapper auto-propagates as_user on preview-aware URLs

The system SHALL provide an `apiFetch` wrapper that, when
`window.__adminPreviewUserId` is set, appends `?as_user=X` to URLs that
match one of the prefixes in `window.__adminPreviewAwarePrefixes`,
left-anchored and absent of an existing `as_user=` query parameter.

#### Scenario: Caller hits a preview-aware URL while impersonating

- **WHEN** `window.__adminPreviewUserId === "3"` AND the wrapper is
  called with `/files/6922976/transcription?anchor_segment_id=42`
- **THEN** the wrapper SHALL request
  `/files/6922976/transcription?anchor_segment_id=42&as_user=3`

#### Scenario: Caller hits a non-preview-aware URL while impersonating

- **WHEN** `window.__adminPreviewUserId === "3"` AND the wrapper is
  called with `/profile`
- **THEN** the wrapper SHALL request `/profile` unchanged

#### Scenario: Caller hits a URL that already carries as_user

- **WHEN** `window.__adminPreviewUserId === "3"` AND the wrapper is
  called with `/mis-avisos/feed?as_user=3`
- **THEN** the wrapper SHALL request it unchanged (no duplicate param)

#### Scenario: Caller hits any URL without impersonation

- **WHEN** `window.__adminPreviewUserId` is null OR undefined
- **THEN** the wrapper SHALL request URLs unchanged regardless of
  prefix
