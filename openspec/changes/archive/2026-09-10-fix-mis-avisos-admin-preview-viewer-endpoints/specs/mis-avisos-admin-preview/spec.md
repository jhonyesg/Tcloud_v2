## ADDED Requirements

### Requirement: Unified viewer endpoints participate in admin preview

When impersonation is active in a request, the unified transcript viewer
endpoints SHALL honor `?as_user=X` exactly like the existing Mis Avisos
JSON endpoints. Specifically:

- `GET /files/{file}/transcription` (FileController@transcription)
- `GET /files/{file}/preview` (FileController@preview)
- `GET /files/{file}/clip-thumbs` (MediaClipController@thumbnails)
- `GET /files/{file}/clip-thumb/{n}` (MediaClipController@thumb)

SHALL be inside the same `['auth', 'misavisos', AdminPreviewSwap::class]`
middleware group as the existing Mis Avisos endpoints.

#### Scenario: Admin impersonates and opens the unified viewer for a file in a non-admin-accessible storage

- **WHEN** the authenticated session user has `role === 'admin'` AND
  the request URL is `GET /mis-avisos?as_user={clientId}` AND the
  impersonated client has `transcription_access = true` on the storage
  that holds the file AND the admin does NOT have
  `transcription_access` on that same storage
- **THEN** clicking "Ver transcripción" on a hit row for that file
  SHALL successfully load the transcript modal (200 response with
  metadata and segments) instead of returning HTTP 404 "No encontrada"

#### Scenario: Admin impersonates and triggers the clip flow from the viewer

- **WHEN** the authenticated session user has `role === 'admin'` AND
  `?as_user={clientId}` is active AND the admin clicks "Generar corte"
  inside the unified viewer modal
- **THEN** the resulting `GET /files/{file}/clip-thumbs` and
  `GET /files/{file}/clip-thumb/{n}` requests SHALL honor the swap
  and return the impersonated client's clip thumbnails instead of
  running under the admin's session

### Requirement: Preview-aware prefix list is the single source of truth

The system SHALL expose
`AdminPreviewSwap::PREVIEW_AWARE_PREFIXES` as the authoritative list of
URL prefixes that participate in admin preview. The routes group
placement AND the apiFetch wrapper that auto-propagates `?as_user=X`
SHALL both derive their behavior from this constant — no hardcoded
prefix lists may drift independently in Blade patches or in route
definitions.

#### Scenario: Constant is exposed from the middleware class

- **WHEN** a developer reads `AdminPreviewSwap::PREVIEW_AWARE_PREFIXES`
- **THEN** it SHALL return an array that includes the prefixes
  `mis-avisos`, `files` and `media` at minimum

#### Scenario: apiFetch wrapper reads the same list

- **WHEN** the apiFetch wrapper checks whether a URL should propagate
  `?as_user=X`
- **THEN** it SHALL match against `window.__adminPreviewAwarePrefixes`
  (injected from `AdminPreviewSwap::PREVIEW_AWARE_PREFIXES`), not
  against a hardcoded prefix string
