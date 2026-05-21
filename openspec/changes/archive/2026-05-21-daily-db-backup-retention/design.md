## Context

TCloud uses PostgreSQL 17 running in a Docker container (`tcloud_postgres`) for all application data. The database currently has no automated backup mechanism. The existing manual backup is outdated. The postgres container is accessible at `127.0.0.1:5432` with credentials `cloud`/`cloud123`, database `tcloudstorage`.

The backup script will run on the host machine and execute `pg_dump` inside the Docker container using `docker exec`.

## Goals / Non-Goals

**Goals:**
- Automated daily PostgreSQL backup via cron job
- Compressed backups (gzip) to minimize disk usage
- 20-day retention with automatic cleanup of old backups
- Timestamped filenames for easy identification
- Backup directory at project root: `backup/db/`

**Non-Goals:**
- No cloud/remote backup (S3, etc.) — local only for now
- No WAL archiving or point-in-time recovery
- No backup verification/integrity checks
- No notification system for backup failures
- No Laravel application changes — pure infrastructure

## Decisions

### Decision 1: `docker exec` + `pg_dump` vs host `pg_dump`
**Choice**: Use `docker exec tcloud_postgres pg_dump` from the host script.

**Rationale**: The postgres client tools are already inside the container. No need to install `pg_dump` on the host. The container has `pg_dump` from the `postgres:17-alpine` image.

**Alternative considered**: Install `postgresql-client` on host — rejected to avoid host dependencies.

### Decision 2: gzip compression
**Choice**: Pipe `pg_dump` output through `gzip` for compression.

**Rationale**: SQL dumps compress 8-10x typically. A 500MB dump becomes ~50MB. The `gzip` utility is available on the host Linux system.

**Alternative considered**: `pg_dump -Fc` (custom format) — rejected because plain SQL + gzip is more portable and human-readable if needed.

### Decision 3: Retention cleanup via `find -mtime`
**Choice**: Use `find` with `-mtime +20` to delete backups older than 20 days.

**Rationale**: Simple, reliable, no extra dependencies. The `find` command is universally available on Linux.

### Decision 4: Backup file naming convention
**Choice**: `tcloudstorage_YYYY-MM-DD_HH-MM-SS.sql.gz`

**Rationale**: ISO-style timestamp sorts lexicographically, making it easy to list and identify backups.

### Decision 5: Single script with cron
**Choice**: One self-contained shell script (`scripts/backup-db.sh`) invoked by host cron.

**Rationale**: Keeps it simple. No Docker cron container needed. The host cron is already running for other tasks.

## Risks / Trade-offs

- **[Risk] Disk space exhaustion** → Mitigation: 20-day retention limits total backup size. Script logs disk usage after each run.
- **[Risk] Backup during write traffic** → Mitigation: `pg_dump` uses consistent snapshot by default for PostgreSQL. No locking issues.
- **[Risk] Cron not configured** → Mitigation: Script includes setup instructions. Manual run is also supported.
- **[Trade-off] No remote backup** → Accepted for now. Local backups protect against accidental deletion/app errors but not hardware failure.
