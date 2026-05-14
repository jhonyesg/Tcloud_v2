## Context

This change is a bug fix only. No new requirements are being introduced and no existing requirements are being modified. The PostgresAdminController's schema loading simply needs to use the correct environment variable names and accept credentials from the request body like other methods already do.

This is a pure implementation fix with no behavioral changes to the PostgreSQL Admin module beyond correcting the connection parameter resolution.

## ADDED Requirements

### Requirement: PostgresAdminController schema endpoint accepts request body credentials

The `getSchema()` method SHALL accept PostgreSQL connection credentials from the request body (host, port, database, username, password) when provided, falling back to environment variables otherwise. This matches the behavior already implemented in `testConnection()`.

#### Scenario: Schema load with request body credentials
- **WHEN** the frontend calls `GET /admin/postgres/schema` with credentials in the JSON body
- **THEN** the controller uses the provided credentials to connect to PostgreSQL
- **AND** returns the database schema as JSON

#### Scenario: Schema load without request body credentials
- **WHEN** the frontend calls `GET /admin/postgres/schema` without credentials in the request body
- **THEN** the controller falls back to environment variables (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
- **AND** uses `config('database.connections.pgsql')` as the base configuration

#### Scenario: Schema load with invalid credentials
- **WHEN** the controller receives invalid PostgreSQL connection parameters
- **THEN** it returns a JSON error response with `success: false` and a descriptive message
- **AND** HTTP status code 500

### Requirement: PostgresAdminController uses correct environment variable names

All methods in PostgresAdminController SHALL use Laravel's `config('database.connections.pgsql')` for default connection parameters, ensuring consistency with the application's `.env` configuration which uses `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

#### Scenario: Environment variable alignment
- **WHEN** a method reads `env('PG_HOST', 'postgres')`
- **THEN** it SHALL be changed to use `config('database.connections.pgsql.host')` or the equivalent `DB_HOST` from `.env`
- **AND** all `PG_*` variable references SHALL be replaced with `DB_*` equivalents

## Non-Goals

- No database schema changes
- No new capabilities introduced
- No changes to frontend UI/UX behavior
- No changes to API contract (routes remain the same)

## Impact

**Files Modified**:
- `app/app/Http/Controllers/PostgresAdminController.php` - fix credential resolution in all methods
- `app/resources/views/admin/postgres.blade.php` - fix Alpine.js form defaults to use `DB_*` vars

**Backward Compatibility**:
- Existing behavior preserved when valid credentials are provided
- Only the failure case (invalid/missing credentials) is fixed