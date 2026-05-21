## Why

The PostgreSQL database has a **71% cache hit ratio** (should be >99%), missing critical indexes, 167 MB of unused indexes, redundant indexes, missing extension (`pg_trgm`), and the Laravel codebase has multiple N+1 query patterns and unbounded `::all()` calls that load entire tables into memory. Together these cause excessive disk I/O, memory waste, and slow response times that will degrade as data grows (833K files today, growing daily).

A full audit identified: 2 critical config issues, 1 missing extension + index, 2 redundant/unused indexes (183 MB wasted), 5 critical N+1 patterns, 10+ unbounded queries, and 11 FK inconsistencies between migrations and actual DB state.

## What Changes

### Database / Infrastructure (no code changes)
- Tune PostgreSQL memory config (`shared_buffers`, `work_mem`, `random_page_cost`, `maintenance_work_mem`)
- Install `pg_trgm` extension and create GIN trigram index on `files.name` for fast `ILIKE` searches
- Remove redundant index `idx_shares_token` (duplicates the UNIQUE constraint)
- Evaluate and drop `files_path_storage_provider_id_unique` (167 MB, 0 uses) if confirmed unused by app
- Add missing indexes: `shares.created_by`, `correo_log(sent_at)`, `media_edit_jobs(user_id, status, created_at)`, `canales(grabador_id, usuario_id)`
- Fix 11 FK constraints to match migration intent (CASCADE where migrations specify it)
- Add `correo_log` cleanup job (cron) to delete records older than 90 days
- Add `share_access_log` cleanup job (cron) to delete records older than 180 days

### Code Changes (Laravel)
- Fix 5 critical N+1 query patterns in controllers:
  - `MediaEditorAdminController::users()` — 3N queries → single aggregate
  - `FileController::downloadMulti()` — N File::find() → single `whereIn`
  - `CanalController::create()` — 2N queries in loop → eager load + aggregate
  - `SessionService::cleanExpired()` — loop delete → single `->delete()`
  - `SessionService::cleanOrphans()` — `::all()` → chunked processing
- Add pagination to 5+ admin list endpoints that currently load all rows
- Replace `User::all()` calls with filtered/paginated queries in `StorageProviderController`

### Non-goals
- No schema redesign or major normalization changes — the schema is sound
- No ORM replacement or query builder migration
- No Redis config changes (separate concern)
- No frontend changes

## Capabilities

### New Capabilities
- `db-performance-tuning`: PostgreSQL config optimization, extension management, index lifecycle, automated log cleanup

### Modified Capabilities
- `file-management`: Faster file search via GIN trigram index
- `media-edit-log`: Optimized aggregate queries for admin dashboard
- `session-management`: Bulk cleanup instead of row-by-row deletion

## Impact

- **Files modified**: `docker-compose.production.yml` (PG config), 3-5 Controllers, 1 Service
- **Migrations**: 1 new migration for indexes + extension + cleanup
- **Docker**: PostgreSQL restart required for `shared_buffers` change
- **Risk**: Dropping the 167 MB index needs verification first via query log
- **Expected result**: Cache hit ratio from 71% → >95%, search latency from full-scan to <50ms, 50-80% fewer queries per page load
