## Context

The Avisos Inteligentes module currently stores keywords as global rows in `keywords` joined to users via the `user_keyword` pivot. Each user gets a per-user `keywords_quota` (admin-defined) and CRUD is the only surface in `AvisosInteligentesController::storeKeyword` / `destroyKeyword`. Matching is text-only via `KeywordMatcher`, which is not modified here.

The change introduces a **client-private taxonomy layered on top** of the existing pivot, without changing the global `keywords` table semantics. The pivot gains a nullable `category_id` and a new `keyword_categories` table owns the taxonomy rows.

## Goals / Non-Goals

**Goals:**
- Allow admin to maintain a base set of categories (Político, Artista, Institución) and let clients attach keywords to them.
- Allow each client to create/rename/delete their own private categories in addition to the admin set.
- Render the client's keyword list with inline category selector and a pill filter grouped by category.
- Keep the matching pipeline, alert pipeline, mail cadence, and per-user keyword quota untouched in both code and observed behavior.

**Non-Goals:**
- No LLM-driven auto-categorization (consistent with `ai_suggester_cron_phase_finished_2026_08_21`).
- No multi-category-per-keyword; only 1→1.
- No changes to `keywords` global table semantics; only an additive nullable column on `user_keyword`.
- No changes to cron schedules, supervisor workers, or Redis keys.

## Decisions

### 1. Category table shape and storage location

**Choice:** New `keyword_categories` table with `owner_scope` ENUM-like CHAR (`admin` | `user`), `owner_id` (FK `users.id`, NULL for admin rows), `name`, `slug`, `color_hex`, timestamps. Unique index `(owner_scope, owner_id, slug)`.

**Alternatives considered:**
- *Per-user table with a separate `admin_keyword_categories` table joined by view*: rejected because it complicates reads (the UI needs the merged list as a single collection).
- *Categories as enum on `user_keyword`*: rejected because clients cannot create new categories without a deploy.

**Why this wins:** A single table with a polymorphic-ish `owner_*` discriminator keeps the read path trivial (`WHERE owner_scope='admin' OR (owner_scope='user' AND owner_id=:userId)`), enforces uniqueness per scope, and lets one model (`KeywordCategory`) serve both.

### 2. Where to store the category assignment on a keyword

**Choice:** Add `category_id` NULLABLE column on `user_keyword` with FK to `keyword_categories.id` and `ON DELETE SET NULL`.

**Alternatives considered:**
- *New junction table `user_keyword_category`*: rejected for 1→1 cardinality; nullable FK is leaner and matches the existing pivot idiom in this codebase.
- *Add `category_id` to `keywords` (global table)*: rejected because the global `keywords` row is shared across users; category is per-user.

**Why this wins:** Keeps the global keyword row untouched (preserves current matching by `KeywordMatcher` which queries `user_keyword`). Cascade-to-NULL on category delete ensures keyword deletion never cascades by accident.

### 3. Slug generation and uniqueness

**Choice:** Slug = `Str::slug($name)` lower-cased and trimmed; collisions within the same scope are returned as 422, never auto-suffixed.

**Why:** Auto-suffixing (`politico-2`) is hostile to UX and to subsequent renames. Returning 422 keeps the slug a true identifier.

### 4. Color storage

**Choice:** `color_hex` CHAR(7) storing `#RRGGBB`. Validated client- and server-side against a regex.

**Why:** Lowest-friction storage for the UI; Tailwind inline classes can use `style="background: {{ hex }}"`. No need for a separate color-picker table.

### 5. Card quota (configurable cap on client categories)

**Choice:** Read `system_settings.avisos_user_category_quota` (int, default NULL = unlimited). Enforced only on POST `/categories`.

**Why:** Keeps the door open for an admin-set cap without forcing one. Matches the existing pattern where `keywords_quota` is configurable.

### 6. Endpoint contracts and visibility rules

| Endpoint | Audience | Visibility rule |
|---|---|---|
| `GET /ia/avisos-inteligentes/{user}/categories` | client (self) or admin | `owner_scope='admin' OR (owner_scope='user' AND owner_id=:userId)` |
| `POST /ia/avisos-inteligentes/{user}/categories` | client (self) | inserts with `owner_scope='user', owner_id=session('user_id')` |
| `PATCH /ia/avisos-inteligentes/{user}/categories/{id}` | client (self) OR admin | must match the same visibility rule on the existing row |
| `DELETE /ia/avisos-inteligentes/{user}/categories/{id}` | client (self) OR admin | deletes; FK `SET NULL` orphans the keyword's category assignment |
| `PATCH /ia/avisos-inteligentes/{user}/keywords/{id}` | client (self) | accepts `category_id` only if visible for `:userId` |
| `POST /ia/avisos-inteligentes/{user}/keywords` | client (self) | accepts optional `category_id` |
| `POST /admin/avisos/categories` | admin | inserts with `owner_scope='admin'` |
| `PATCH /admin/avisos/categories/{id}` | admin | updates; FK handles existing assignments |
| `DELETE /admin/avisos/categories/{id}` | admin | deletes; existing assignments become `NULL` |

All endpoints return JSON; the existing `storeKeyword` response shape gets a new optional `category` field.

### 7. UI surface (Blade + Alpine)

- `resources/views/ia/avisos-inteligentes/user-detail.blade.php`:
  - Add a horizontal scrollable row of pill buttons above the keyword list: `[Todas] [Sin categoría] [🏛️ Político] [...] ... [+ Nueva categoría]`.
  - Pill colors come from `category.color_hex`.
  - Selecting a pill sets `activeCategory` (string: `'all'` | `'none'` | `<categoryId>`); `filteredKeywords` is a computed getter.
  - Each keyword row gets an inline `<select x-model="kw.category_id" @change="updateKeywordCategory(kw)">` fed by the merged category list.
  - The "Agregar keyword" form gains an optional category select (same list).
  - Adding a new category opens a small inline form (name + color picker) that POSTs to `/categories` then pushes the result into `categories[]`.
- New `resources/views/admin/avisos/categories.blade.php`: 3-column table (Name / Slug (auto-derived, read-only) / Color) with inline edit + delete confirm.

**Why Alpine over a full reload:** Existing module already uses Alpine (`x-data="userDetail(...)"` at line 6 of `user-detail.blade.php`); extending it avoids any build-step change and matches the established pattern in the file.

### 8. Seed and migration order

Three migrations, run in this order on `php artisan migrate`:

1. `2026_09_xx_120000_create_keyword_categories_table.php` — creates the table and indexes.
2. `2026_09_xx_120001_add_category_id_to_user_keyword_table.php` — adds nullable FK + `SET NULL` on delete.
3. `2026_09_xx_120002_seed_admin_keyword_categories.php` — idempotent insert of the three base rows (uses `INSERT ... ON CONFLICT DO NOTHING` semantics via `firstOrCreate` against `name`).

### 9. Why not reuse the existing `Keywords/Keyword` model

`Keyword` stays as the global token model. A new `KeywordCategory` model owns the taxonomy; `Keyword::category()` is the inverse relation through `user_keyword.category_id`. This avoids polluting the global keyword row or the existing pivot metadata.

## Risks / Trade-offs

- **Risk:** A bulk pre-existing keyword set with hundreds of rows under "Sin categoría" creates visual clutter. **Mitigation:** the pill filter hides them; the empty-state CTA invites the client to "clasificar ahora".
- **Risk:** Renaming a base category breaks saved client muscle memory (and any external links if the slug was used in URLs — it isn't). **Mitigation:** the spec mandates non-destructive rename; clients see the new name after refresh.
- **Risk:** Two clients using the same custom name (`Tema`) collide in the user's mind when an admin views support logs. **Mitigation:** the spec keeps each client's row private; admin views never see client categories.
- **Risk:** Cascade `SET NULL` could surprise a client deleting a category that has 47 keywords assigned. **Mitigation:** the DELETE endpoint returns the affected count; the UI shows a confirm dialog with that number before submitting.

## Migration Plan

1. Run the three migrations in order (see Decisions §8). All additive: nullable column + idempotent seed.
2. Deploy the new controller methods and view; no worker restart required (`KeywordMatcher` and crons are untouched).
3. Verify in production: pick one client, create a custom category, assign it to an existing keyword, refresh and confirm filter pill + inline selector work.
4. **Rollback:** drop `category_id` column (nullable, empty → safe); drop `keyword_categories` table; the three migrations are reversible.

## Open Questions

- Whether to also expose a bulk-classify modal ("asignar X a las 12 keywords seleccionadas") is a future nice-to-have but not required by the spec; can be added later without schema changes.
