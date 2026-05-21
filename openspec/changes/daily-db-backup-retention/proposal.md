## Why

The current database backup is outdated and there is no automated backup system. A PostgreSQL database powers the entire TCloud platform (users, files, shares, storage assignments). Without regular backups, a data loss event would be catastrophic and unrecoverable. An automated daily backup with 15-20 day retention ensures data safety without manual intervention.

## What Changes

- Add a backup directory (`backup/db/`) at the project root to store PostgreSQL dump files
- Create a shell script (`scripts/backup-db.sh`) that:
  - Runs `pg_dump` against the `tcloud_postgres` Docker container
  - Names files with timestamp format: `tcloudstorage_YYYY-MM-DD_HH-MM-SS.sql.gz`
  - Compresses dumps with gzip to save disk space
  - Deletes backups older than 20 days automatically
- Add a cron job entry (documented in README or setup script) to run the backup daily
- Initial manual backup of the current database state

## Capabilities

### New Capabilities
- `db-backup-automation`: Automated PostgreSQL backup with pg_dump, gzip compression, timestamped filenames, and retention-based cleanup of old backups

### Modified Capabilities

## Impact

- **Files created**: `scripts/backup-db.sh`, `backup/db/` directory
- **Docker**: Uses existing `tcloud_postgres` container (no changes to docker-compose)
- **Dependencies**: `pg_dump` available inside postgres:17-alpine container, `gzip` on host
- **Disk usage**: ~50-200MB per backup depending on DB size, ~1-4GB for 20 days of retention
- **No migration required**
- **No code changes** to Laravel application — this is infrastructure/scripts only
