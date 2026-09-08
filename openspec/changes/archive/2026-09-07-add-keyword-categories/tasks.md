## 1. Database migrations

- [x] 1.1 Create `app/database/migrations/2026_09_xx_120000_create_keyword_categories_table.php` with columns `id`, `owner_scope` (char(8)), `owner_id` (nullable FK `users.id`), `name` (varchar 80), `slug` (varchar 96), `color_hex` (char(7)), `timestamps`; unique `(owner_scope, owner_id, slug)`; index `(owner_scope, owner_id)`.
- [x] 1.2 Create `app/database/migrations/2026_09_xx_120001_add_category_id_to_user_keyword_table.php` adding nullable `category_id` (FK `keyword_categories.id` `ON DELETE SET NULL`) + index.
- [x] 1.3 Create `app/database/migrations/2026_09_xx_120002_seed_admin_keyword_categories.php` idempotently inserting the three base categories `Político` (#0EA5E9), `Artista` (#F59E0B), `Institución` (#10B981).
- [x] 1.4 Run `php artisan migrate` against the local DB and verify schema with `psql \d keyword_categories` + `\d user_keyword`.

## 2. Models and relations

- [x] 2.1 Create `app/app/Models/KeywordCategory.php` with `$fillable = ['owner_scope','owner_id','name','slug','color_hex']`, `users()` BelongsTo (admin rows have no owner), `keywords()` HasMany through `user_keyword`, scope `visibleFor($userId)` returning admin rows + that user's own.
- [x] 2.2 Extend `app/app/Models/Keyword.php` with `category()` relation through `userKeyword()->category()`, and `scopeInCategory($id)` and `scopeWithoutCategory()` on `UserKeyword`.
- [x] 2.3 Add helper `KeywordCategory::normalizeSlug(string $name): string` using `Str::slug()` plus a uniqueness check.

## 3. Client-facing controller endpoints

- [x] 3.1 In `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`, add `indexCategories($userId)` returning the merged visible list with a count of assigned keywords per category and the count for `null`.
- [x] 3.2 Add `storeCategory(Request, $userId)`: validate `name|max:80`, `color_hex` regex; check `avisos_user_category_quota` if set; persist with `owner_scope='user', owner_id=session('user_id')`; return 201 with the row.
- [x] 3.3 Add `updateCategory(Request, $userId, $categoryId)`: enforce visibility (must belong to user or be admin); partial-update `name` and/or `color_hex`; regenerate slug if name changes and check uniqueness.
- [x] 3.4 Add `destroyCategory($userId, $categoryId)`: enforce visibility; return count of `user_keyword` rows whose `category_id` will be set to NULL; on confirm, delete the category.
- [x] 3.5 Extend `storeKeyword` to accept optional `category_id` (validated against `visibleFor($userId)`); include `category` in the JSON response when set.
- [x] 3.6 Add `updateKeyword(Request, $userId, $keywordId)`: validate `category_id` is either null or visible for the user; update the pivot row; return updated keyword with category.

## 4. Admin controller endpoints

- [x] 4.1 Create `app/app/Http/Controllers/Admin/AvisosCategoriesController.php` with `index`, `store`, `update`, `destroy` restricted to `admin` middleware; `store` and `update` use `owner_scope='admin', owner_id=null`; `destroy` returns the same NULL-cascade count.
- [x] 4.2 Add the admin routes inside the `'admin'` middleware group in `app/routes/web.php`.

## 5. Routes

- [x] 5.1 Add 6 client routes under the `auth` group in `app/routes/web.php` (per Decisions §6 of `design.md`).
- [x] 5.2 Add a sub-nav entry linking to the admin categories page from the existing `admin/avisos-inteligentes` landing.

## 6. Client UI (Blade + Alpine)

- [x] 6.1 In `resources/views/ia/avisos-inteligentes/user-detail.blade.php`, add the pill-filter row (Todas / Sin categoría / each visible category / + Nueva categoría) above the keyword list. Pills reflect `category.color_hex` as background.
- [x] 6.2 Add Alpine state `activeCategory` (default `'all'`), getter `filteredKeywords` that filters by active category, and method `selectCategory(id|null|'all'|'none')`.
- [x] 6.3 Add inline `<select>` per keyword row fed by the merged categories list; on change, calls `updateKeywordCategory(kw)` which PATCHes `/keywords/{id}` and reloads the row.
- [x] 6.4 Extend the "Agregar keyword" form with an optional `<select>` for category sourced from the same list; include `category_id` in the POST body.
- [x] 6.5 Add the "Nueva categoría" inline form (name input + color picker) that POSTs to `/categories`, appends to `categories[]` on success, and shows a toast on 422.
- [x] 6.6 Update the existing keyword counter (`used/quota`) display to include a small badge showing "X clasificadas de N" so the client can see progress.

## 7. Admin UI

- [x] 7.1 Create `resources/views/admin/avisos/categories.blade.php` extending `layouts.app` with a 3-column table (Name / Slug / Color) and inline create/edit/delete actions.
- [x] 7.2 Add a delete confirm dialog showing the count of `user_keyword` rows that will lose their category.
- [x] 7.3 Add a link from the admin landing page (`admin/avisos-inteligentes`) sidebar to the new page.

## 8. Wiring and validation

- [x] 8.1 Smoke-test: log in as admin → confirm the three base categories appear for any client; create a new base category and refresh a client panel to see it.
- [x] 8.2 Smoke-test as client: create a custom category, assign it to a new keyword, change it via the inline `<select>`, delete it and confirm the keyword falls to "Sin categoría".
- [x] 8.3 Smoke-test isolation: client A creates `Tema`; client B's GET `/categories` does not include it.
- [x] 8.4 Smoke-test quota: with `avisos_user_category_quota = 2`, attempt to create a third and confirm 422.
- [x] 8.5 Smoke-test matching: assign category to a keyword, run a `transcription:scan-and-submit` dry run, confirm the keyword still matches and emits a `keyword_match` (no behavior change).

## 9. Rollout and rollback

- [x] 9.1 Document rollback steps in the change README (drop `category_id` column → drop `keyword_categories` table) and confirm that the down migrations are reversible.
- [x] 9.2 After merge to `main` and deploy, watch `laravel.log` for the next 24h for any 500s on the new endpoints; abort and rollback if observed.
