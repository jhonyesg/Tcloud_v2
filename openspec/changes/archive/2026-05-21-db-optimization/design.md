## Context

TCloud uses PostgreSQL 17 in Docker (`tcloud_postgres`) with 415 MB total database size. The `files` table dominates at 405 MB (194 MB data + 211 MB indexes) with 833K rows. Current PostgreSQL config is the Docker default (`shared_buffers=128MB`, `work_mem=4MB`, `random_page_cost=4`), producing a 71% cache hit ratio. The application has multiple N+1 query patterns discovered via code analysis.

### Current Database Profile
```
Total DB:          415 MB
files table:       194 MB data + 211 MB indexes = 405 MB (97.6%)
storage_providers: 176 (165 shared + 11 personal)
users:             11
files:             833,069
Cache hit ratio:   71.35% (target: >95%)
pg_trgm:           NOT INSTALLED
GIN trigram index: DOES NOT EXIST
```

## Goals / Non-Goals

**Goals:**
- Bring cache hit ratio above 95% via config tuning
- Enable fast file name search via GIN trigram index
- Eliminate redundant/unused indexes saving ~183 MB
- Fix the most impactful N+1 query patterns
- Add pagination to unbounded admin queries
- Add automated cleanup for log tables

**Non-Goals:**
- No schema redesign (normalization is adequate)
- No new tables or major structural changes
- No frontend/UI changes
- No Redis configuration changes
- No query builder migration (keep Eloquent)

## Decisions

### Decision 1: PostgreSQL Config via `docker-compose` Command Args

**Choice**: Pass PG config overrides via `command:` in `docker-compose.production.yml`.

**Rationale**: The `postgres:17-alpine` image accepts config flags via `command:`. This avoids mounting a custom `postgresql.conf` file and keeps all config in version control via docker-compose.

```
command: >
  postgres
    -c shared_buffers=512MB
    -c work_mem=16MB
    -c maintenance_work_mem=256MB
    -c effective_cache_size=2GB
    -c random_page_cost=1.1
    -c log_min_duration_statement=500
```

**Alternative considered**: Mount custom `postgresql.conf` — more flexible but harder to version-control individual settings.

### Decision 2: pg_trgm Extension Install via Migration

**Choice**: Install `pg_trgm` via a Laravel migration using `DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm')`.

**Rationale**: Extensions should be managed alongside schema changes. The existing migration `2026_05_09_164518_add_gin_index_to_files_name.php` already attempts this but appears to have failed (extension not installed, index not created). We'll create a new migration that succeeds.

### Decision 3: Drop `files_path_storage_provider_id_unique` — Verify First

**Choice**: Before dropping, verify the app doesn't depend on it by checking Eloquent queries and the `File` model's `$unique` rules. The index has `idx_scan = 0` but the UNIQUE constraint may be relied upon by `File::create()` for path uniqueness enforcement.

**Verification**: Check if `File::create()` or any validation relies on a DB-level unique constraint for `(path, storage_provider_id)`. If so, we keep the constraint but rebuild it as a smaller index. If not, we drop it and add a targeted composite index `(storage_provider_id, path)` that better serves `WHERE storage_provider_id = ? AND path LIKE ?` queries.

### Decision 4: Fix FK Cascades — Match Migration Intent

**Choice**: Align DB reality with migration definitions. The migration `2026_05_13_000001_fix_storage_provider_cascade_deletes.php` explicitly sets CASCADE for `files→storage_providers`, `files.parent_id`, `shares→files`, `share_access_log→shares`. But several FKs still show NO ACTION where CASCADE was intended.

**Affected FKs** (where migration specifies CASCADE but DB has NO ACTION):
- `canales.grabador_id` → `grabadores.id` (migration: CASCADE)
- `grabador_usuario.grabador_id` → `grabadores.id` (migration: CASCADE)
- `grabador_usuario.user_id` → `users.id` (migration: CASCADE)

**NOT fixing** (intentional protection):
- `files.owner_id` → `users.id` (NO ACTION — prevents accidental user deletion losing files)
- `shares.created_by` → `users.id` (NO ACTION — same reason)
- `media_edit_jobs.user_id` → `users.id` (NO ACTION)
- `user_storages.user_id` → `users.id` (NO ACTION)
- `canales.usuario_id` → `users.id` (migration originally CASCADE, but NO ACTION is safer)

### Decision 5: N+1 Fix Strategy — Minimal Refactors

**Choice**: Fix N+1 patterns using Eloquent's `with()`, `withCount()`, and `whereIn()` rather than raw SQL. Keep changes minimal and focused.

**Critical fixes:**
1. `MediaEditorAdminController::users()` — Replace loop with `withCount` + aggregate subquery
2. `FileController::downloadMulti()` — Replace `File::find()` loop with `File::whereIn()->with('storageProvider')->get()`
3. `SessionService::cleanExpired()` — Replace foreach delete with `UserSession::where('expires_at', '<', now())->delete()`
4. `SessionService::cleanOrphans()` — Use `chunk(100)` instead of `::all()`
5. `CanalController::create()` — Eager-load grabadores with pivot data

### Decision 6: Pagination for Admin Endpoints

**Choice**: Add Laravel `->paginate(25)` to admin list endpoints. Use `->simplePaginate()` where total count isn't needed.

**Affected endpoints** (currently `->get()` returning all rows):
- `StorageProviderController::index()` — 176 providers
- `SessionController::index()` — variable sessions
- `ShareController::index()` — variable shares per user
- `GrabadorController::index()` — small but will grow
- `CanalController::index()` — small but will grow

### Decision 7: Log Cleanup via Artisan Command + Cron

**Choice**: Create an artisan command `db:cleanup-logs` that deletes old `correo_log` (>90 days) and `share_access_log` (>180 days) records. Schedule via Laravel's kernel or host cron.

**Rationale**: Keeps cleanup logic in version-controlled PHP code rather than raw SQL cron scripts. Can be extended later for other tables.

## Risks / Trade-offs

- **[Risk] `shared_buffers=512MB` requires PG restart** → Mitigation: Plan restart during low-traffic window. Docker `restart: unless-stopped` ensures auto-recovery.
- **[Risk] Dropping `files_path_storage_provider_id_unique` could allow duplicate paths** → Mitigation: Verify app-level uniqueness enforcement first (Decision 3).
- **[Risk] FK CASCADE changes could delete data unexpectedly** → Mitigation: Only apply to tables where migrations explicitly define CASCADE. Leave user→files protection as NO ACTION.
- **[Trade-off] GIN trigram index increases write latency slightly** → Accepted: `files` table is read-heavy (search/browse) vs write-heavy (upload is infrequent relative to reads).
- **[Trade-off] Pagination changes UI behavior** → Accepted: Admin tables show page controls instead of full list. Better UX for large datasets anyway.
