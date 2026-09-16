## Purpose

Lets each Avisos Inteligentes client organize their own keywords under user-defined categories, with a small admin-curated base set available to everyone, and filter their keyword list by category. The matching and notification pipeline is unchanged: categories are a visual/filtering aid only.

## Requirements

### Requirement: Admin-managed base category set
The system MUST provide a base set of categories managed by administrators (Político, Artista, Institución) that are visible as suggestions to every client. Only users with the admin role may create, rename, recolor, or delete base categories. Base categories are not owned by any individual user.

#### Scenario: Admin seeds the base set on a fresh deploy
- **WHEN** the seed migration runs against an empty database
- **THEN** the categories `Político`, `Artista`, `Institución` exist with distinct default colors and are visible to every client

#### Scenario: Admin adds a new base category
- **WHEN** an authenticated admin POSTs a new base category with a name and color
- **THEN** the category is saved with `owner_scope = admin` and becomes available as a suggestion to every client on next request

#### Scenario: Admin renames a base category
- **WHEN** an authenticated admin PATCHes a base category with a new name
- **THEN** every client that had keywords assigned to it sees the new name after page refresh; no keyword is lost

#### Scenario: Non-admin attempts to manage base categories
- **WHEN** a client (non-admin) attempts to POST, PATCH, or DELETE any base category
- **THEN** the request is rejected with HTTP 403

### Requirement: Client-owned custom categories
Every client MUST be able to create their own categories in addition to the admin base set. Client-owned categories SHALL be private to the user that created them and MUST NOT be visible to other clients.

#### Scenario: Client creates a custom category
- **WHEN** an authenticated client POSTs a new category with a name and color and has not exceeded their category quota (if any)
- **THEN** the category is saved with `owner_scope = user` and `owner_id = client.id`, and appears in that client's category list immediately

#### Scenario: Client category is private
- **WHEN** client A creates a category named `Equipo`
- **THEN** client B's GET `/categories` response does NOT include `Equipo`

#### Scenario: Two clients use the same name without conflict
- **WHEN** client A and client B both create categories named `Tema`
- **THEN** each client's category exists independently in the database and is only visible to its owner

#### Scenario: Renaming a custom category
- **WHEN** a client PATCHes one of their own categories with a new name
- **THEN** the new name appears in the client's filter pills and inline selectors, while keywords remain assigned

#### Scenario: Deleting a category that has keywords assigned
- **WHEN** a client DELETEs a category that is assigned to one or more of their keywords
- **THEN** the category is removed and the previously-assigned keywords are left without a category (visible under "Sin categoría") rather than being deleted

### Requirement: Assigning a category to a keyword (one-to-one)
A keyword SHALL have zero or one category assigned. Assigning a category is optional and MAY be done at creation time or changed later through a dedicated update endpoint.

#### Scenario: Create a keyword with a category
- **WHEN** a client POSTs a new keyword with `category_id` pointing to a category they can see (admin base or their own)
- **THEN** the keyword is saved with that category and counts against their keyword quota

#### Scenario: Change a keyword's category
- **WHEN** a client PATCHes an existing keyword with a new `category_id`
- **THEN** the keyword is reassigned to the new category; the keyword is not counted against quota again

#### Scenario: Remove a keyword's category
- **WHEN** a client PATCHes an existing keyword with `category_id = null`
- **THEN** the keyword appears in the "Sin categoría" section

#### Scenario: Attempt to assign a category the client cannot see
- **WHEN** a client PATCHes/POSTs a keyword with `category_id` pointing to another client's category
- **THEN** the request is rejected with HTTP 422 and the keyword's category is not changed

### Requirement: Filtering the keyword list by category
The client panel MUST provide a filter control that shows the merged list of admin base categories plus the client's own custom categories, lets the user select one or "Sin categoría" / "Todas", and renders only the matching keywords accordingly.

#### Scenario: Filter by an admin base category
- **WHEN** the client selects the `Político` pill
- **THEN** the panel renders only the client's keywords whose `category_id` resolves to `Político`

#### Scenario: Filter by a custom category
- **WHEN** the client selects one of their own categories
- **THEN** the panel renders only their keywords assigned to that category

#### Scenario: Filter "Sin categoría"
- **WHEN** the client selects the "Sin categoría" pill
- **THEN** the panel renders only the client's keywords with no category assigned (including pre-existing keywords that have never been classified)

#### Scenario: Filter "Todas"
- **WHEN** the client selects the "Todas" pill
- **THEN** the panel renders all the client's keywords regardless of category

#### Scenario: Each keyword shows its current category inline
- **WHEN** the keyword list renders
- **THEN** every keyword row shows the assigned category name and color (or "Sin categoría") alongside the keyword text, with an inline selector to change it without leaving the panel

### Requirement: Keywords without category are not lost
Existing keywords created before this change MUST continue to function (matching, scanning, alerts) exactly as before. They appear under "Sin categoría" in the filter until the client assigns one.

#### Scenario: Pre-existing keyword keeps matching
- **WHEN** a keyword created before this change has no category
- **THEN** the keyword still matches in the scanner and still triggers alerts; only the display/filter behavior changes

#### Scenario: Pre-existing keyword can be classified retroactively
- **WHEN** the client assigns a category to a pre-existing keyword
- **THEN** the keyword moves from "Sin categoría" into the chosen category's filter group on next render

### Requirement: Matching and alerts pipeline is unchanged
Category assignment MUST NOT alter transcription matching, alert generation, mail delivery cadence, or the per-user keyword quota. Scanning, dispatch, and AlertDispatcher behavior is preserved verbatim.

#### Scenario: Matching ignores categories
- **WHEN** the transcriptor scanner runs
- **THEN** it matches against `keywords.text` exactly as before; categories are not consulted during matching

#### Scenario: Keyword quota is unaffected by categories
- **WHEN** a client creates their 50th keyword and assigns it a category
- **THEN** the count against `keywords_quota` is incremented exactly once, regardless of whether the keyword has a category assigned or not
