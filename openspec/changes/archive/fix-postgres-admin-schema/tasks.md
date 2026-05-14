## 1. Fix PostgresAdminController credential resolution

- [x] 1.1 Add `getPgConfig()` helper method that returns connection params from request body OR `config('database.connections.pgsql')`
- [x] 1.2 Refactor `getSchema()` to use `getPgConfig()` instead of hardcoded `env('PG_*', ...)` calls
- [x] 1.3 Refactor `executeQuery()` to use `getPgConfig()` instead of hardcoded `env('PG_*', ...)` calls
- [x] 1.4 Refactor `backupLocal()` to use `getPgConfig()` instead of hardcoded `env('PG_*', ...)` calls
- [x] 1.5 Refactor `backupFtp()` to use `getPgConfig()` instead of hardcoded `env('PG_*', ...)` calls
- [x] 1.6 Refactor `testConnection()` to use `getPgConfig()` (also fixed, was using old env vars)

## 2. Fix postgres.blade.php Alpine.js config defaults

- [x] 2.1 Update `config.host` to use `{{ env("DB_HOST", "127.0.0.1") }}`
- [x] 2.2 Update `config.port` to use `{{ env("DB_PORT", "5432") }}`
- [x] 2.3 Update `config.database` to use `{{ env("DB_DATABASE", "tcloudstorage") }}`
- [x] 2.4 Update `config.username` to use `{{ env("DB_USERNAME", "cloud") }}`
- [x] 2.5 Update `config.password` default to empty string (security best practice)

## 3. Test the fix

- [ ] 3.1 Verify `loadSchema()` no longer returns 500 when clicking "Diagrama" tab
- [ ] 3.2 Verify connection test works with valid credentials
- [ ] 3.3 Verify SQL query execution still works
- [ ] 3.4 Verify backup functionality still works