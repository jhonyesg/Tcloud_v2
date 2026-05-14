## Why

The PostgreSQL Admin module (PostgresAdminController) fails with HTTP 500 when loading the database diagram because it uses environment variable names (`PG_HOST`, `PG_DATABASE`, `PG_USERNAME`, `PG_PASSWORD`) that don't match the Laravel `.env` configuration (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Additionally, `getSchema()` ignores any credentials passed from the frontend form, unlike `testConnection()` which correctly reads from the request body.

## What Changes

- Fix `getSchema()` to accept credentials from the request body (like `testConnection()` does)
- Fix all controller methods to use `DB_*` env var names instead of `PG_*`
- Align the frontend form's default values with the actual `.env` configuration
- Ensure the controller uses consistent credential resolution across all methods

## Capabilities

### New Capabilities
- None (bug fix only)

### Modified Capabilities
- None (no spec-level behavior changes)

## Impact

**Affected Controller**: `app/app/Http/Controllers/PostgresAdminController.php`

**Affected Methods**:
- `getSchema()` - reads `PG_*` vars that don't exist; should read from request body like `testConnection()`
- `executeQuery()` - same issue as `getSchema()`
- `backupLocal()` - same issue
- `backupFtp()` - same issue

**Affected View**: `app/resources/views/admin/postgres.blade.php`
- Alpine.js form defaults reference `PG_HOST`, `PG_PORT`, `PG_DATABASE`, `PG_USERNAME` which don't exist in `.env`

**Routes**: All `/admin/postgres/*` routes remain unchanged (behavior fix only)

## Non-Goals
- No database schema changes
- No new capabilities
- No changes to the frontend UI behavior (tabs, diagram interaction)