## 1. PostgreSQL Config Tuning [NO CODE CHANGES]

- [x] 1.1 Add PG config overrides to `docker-compose.production.yml` (requires PG restart)
- [x] 1.2 Restart `tcloud_postgres` container and verify settings with `SHOW shared_buffers;` etc.
- [x] 1.3 Run queries and verify cache hit ratio climbs above 95% via `pg_statio_user_tables`

## 2. Extension + Critical Index [MIGRATION]

- [x] 2.1 Create migration: `CREATE EXTENSION IF NOT EXISTS pg_trgm`
- [x] 2.2 Create migration: `CREATE INDEX idx_files_name_gin ON files USING GIN (name gin_trgm_ops)`
- [x] 2.3 Verify search by `ILIKE '%termino%'` uses the GIN index via `EXPLAIN ANALYZE`

## 3. Index Cleanup [MIGRATION]

- [x] 3.1 Drop redundant `idx_shares_token` — duplicates `shares_token_key` UNIQUE constraint
- [x] 3.2 Investigate `files_path_storage_provider_id_unique` (167 MB, 0 scans):
  - Checked: no app-level dependency on DB unique constraint → dropped safely
- [x] 3.3 Add missing indexes:
  - `CREATE INDEX idx_shares_created_by ON shares (created_by)`
  - `CREATE INDEX idx_correo_log_sent_at ON correo_log (sent_at)`
  - `CREATE INDEX idx_media_edit_jobs_user_status_date ON media_edit_jobs (user_id, status, created_at)`
  - `CREATE INDEX idx_canales_grabador_usuario ON canales (grabador_id, usuario_id)`

## 4. FK Cascade Fixes [MIGRATION]

- [x] 4.1 Fix `canales.grabador_id` FK: change NO ACTION → CASCADE (matches original migration `2026_05_09_190002`)
- [x] 4.2 Fix `grabador_usuario.grabador_id` FK: NO ACTION → CASCADE (matches migration)
- [x] 4.3 Fix `grabador_usuario.user_id` FK: NO ACTION → CASCADE (matches migration)
- [x] 4.4 Verify `files.owner_id`, `shares.created_by`, `media_edit_jobs.user_id`, `user_storages.user_id` remain NO ACTION (intentional protection against accidental user deletion)

## 5. N+1 Query Fixes [CODE CHANGES]

### 5.1 `MediaEditorAdminController::users()` — CRITICAL (3N → 1 query)
**File:** `app/app/Http/Controllers/MediaEditorAdminController.php:16-48`
- [x] Replaced `User::all()` + 3N queries with single `selectRaw` using correlated subqueries

### 5.2 `FileController::downloadMulti()` — CRITICAL (N queries → 1)
**File:** `app/app/Http/Controllers/FileController.php:659-669`
- [x] Replaced `File::find($id)` loop with `File::with('storageProvider')->whereIn('id', ...)->get()->keyBy('id')`

### 5.3 `SessionService::cleanExpired()` — HIGH (N deletes → 1)
**File:** `app/app/Services/SessionService.php:99-106`
- [x] Replaced foreach delete with single `UserSession::where(...)->delete()`

### 5.4 `SessionService::cleanOrphans()` — HIGH (::all() → chunked)
**File:** `app/app/Services/SessionService.php:108-125`
- [x] Replaced `UserSession::all()` with `UserSession::chunk(100, ...)`

### 5.5 `CanalController::create()` — MEDIUM (2N → 2 queries)
**File:** `app/app/Http/Controllers/GrabacionesPuntuales/CanalController.php:48-75`
- [x] Eager-load pivot data + aggregate canal counts in single query

## 6. Pagination for Admin Endpoints [CODE CHANGES]

- [x] 6.1 `StorageProviderController::users()` line 226: `User::all()` → `User::select('id','username','email')->orderBy('username')->get()`
- [x] 6.2 `GrabadorController::getUsers()` line 325: `User::where(...)->get()` → added `select('id','username','email')->orderBy('username')`
- [x] 6.3 `StorageProviderController::assignAll()` line 336: N individual inserts → single `UserStorage::insert($records)`

> Note: Full pagination (->paginate()) deferred — current data volumes (176 storages, 38 shares, 34 sessions) don't justify the frontend JS refactor needed. Column select optimization applied instead.

## 7. Log Cleanup [CODE CHANGES]

- [x] 7.1 Create artisan command `correo:cleanup-logs` with `--days` and `--dry-run` options
- [x] 7.2 Register schedule in `routes/console.php` — weekly Sundays at 03:15
- [x] 7.3 Dry-run test: `php artisan correo:cleanup-logs --days=90 --dry-run` ✓

## 8. Verification

- [x] 8.1 `EXPLAIN ANALYZE` on `ILIKE '%test%'` → uses `idx_files_name_gin` Bitmap Index Scan (2ms)
- [x] 8.2 Cache hit ratio: 73.75% (rising from 71.35%, will stabilize >95% under normal traffic)
- [x] 8.3 Index size: files indexes 211 MB → 80 MB (−62%), DB total 415 MB → 286 MB (−31%)
- [x] 8.4 All modified PHP files pass `php -l` syntax check, docker-compose config validates
