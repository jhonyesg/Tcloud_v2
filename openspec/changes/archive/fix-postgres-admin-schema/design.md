## Context

The `PostgresAdminController` provides a web-based PostgreSQL administration interface with four tabs: Configuration, Diagram, Query SQL, and Backup. The module allows users to test connections, visualize the database schema as an interactive diagram, run read-only SQL queries, and create backups.

**Current Problem**: The `getSchema()` endpoint (called when switching to the "Diagram" tab) uses hardcoded environment variable names (`PG_HOST`, `PG_DATABASE`, etc.) that don't exist in the Laravel `.env` file. The application uses `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` instead. Additionally, `getSchema()` ignores credentials passed from the frontend, unlike `testConnection()` which correctly reads from the request body.

**Affected Methods**:
- `getSchema()` - line 111-201
- `executeQuery()` - line 203-271
- `backupLocal()` - line 273-430
- `backupFtp()` - line 466-547

**Affected View**:
- `postgres.blade.php` lines 325-329 - Alpine.js config defaults

## Goals / Non-Goals

**Goals:**
- Fix credential resolution in all PostgresAdminController methods to use Laravel's `config('database.connections.pgsql')` or the correct `DB_*` env vars
- Make `getSchema()` accept credentials from request body (consistent with `testConnection()`)
- Fix frontend Alpine.js form defaults to reference correct env var names

**Non-Goals:**
- No database schema changes
- No new features or capabilities
- No changes to the frontend tab behavior or UI interactions
- No changes to API routes

## Decisions

### 1. Use Laravel's database config as primary source

**Decision**: All controller methods should use `config('database.connections.pgsql')` to get default connection parameters. This ensures the controller uses the same configuration as the rest of the Laravel application.

**Rationale**: The `.env` file already defines `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Laravel's database config reads these via `env()` calls. Using `config()` directly is more idiomatic than accessing `$_ENV` or `getenv()` directly.

**Alternative considered**: Reading `$_ENV` directly for `DB_*` vars. Rejected because `config()` respects Laravel's configuration caching and is the standard way to access database settings in Laravel.

### 2. Accept request body credentials like `testConnection()` does

**Decision**: `getSchema()`, `executeQuery()`, `backupLocal()`, and `backupFtp()` should accept credentials from the request body when provided, falling back to `config()` otherwise.

**Rationale**: The frontend already sends credentials in the request body for `testConnection()`. Making all methods consistent allows the user to override defaults without modifying `.env`.

**Implementation pattern** (from existing `testConnection()`):
```php
$host = $request->input('host') ?: config('database.connections.pgsql.host');
$port = $request->input('port') ?: config('database.connections.pgsql.port');
$database = $request->input('database') ?: config('database.connections.pgsql.database');
$username = $request->input('username') ?: config('database.connections.pgsql.username');
$password = $request->input('password') ?: config('database.connections.pgsql.password');
```

### 3. Frontend Alpine.js config should use `DB_*` vars

**Decision**: The view's Alpine.js `config` object should reference `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` instead of `PG_*`.

**Rationale**: The `.env` file only defines `DB_*` vars. Using `PG_*` causes Alpine.js to fall back to hardcoded defaults that don't match production.

**Change required in `postgres.blade.php`** (lines 324-329):
```javascript
// Before (wrong):
config: {
    host: '{{ env("PG_HOST", "postgres") }}',
    ...
}

// After (correct):
config: {
    host: '{{ env("DB_HOST", "127.0.0.1") }}',
    database: '{{ env("DB_DATABASE", "tcloudstorage") }}',
    username: '{{ env("DB_USERNAME", "cloud") }}',
    ...
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| Breaking existing connections for users who had working `PG_*` vars | Only affects users whose .env lacks `PG_*` vars (none should exist since the app uses `DB_*`) |
| `testConnection()` behavior changes | No - `testConnection()` already uses request body correctly |
| Different default values in view vs controller | Ensure both use the same fallback defaults |

## Migration Plan

1. **Deploy**: Apply code changes to controller and view
2. **Verify**: Test the Diagram tab loads successfully in the PostgreSQL Admin module
3. **Rollback**: Revert to previous commit if issues arise

## Open Questions

None - the fix is straightforward and low-risk.