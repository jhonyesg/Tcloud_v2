## 1. Setup

- [x] 1.1 Create `backup/db/` directory at project root
- [x] 1.2 Create `scripts/` directory at project root

## 2. Backup Script

- [x] 2.1 Create `scripts/backup-db.sh` with `docker exec` pg_dump command targeting `tcloud_postgres` container
- [x] 2.2 Add gzip compression to backup output
- [x] 2.3 Implement timestamped filename: `tcloudstorage_YYYY-MM-DD_HH-MM-SS.sql.gz`
- [x] 2.4 Add automatic cleanup of backups older than 20 days using `find -mtime +20`
- [x] 2.5 Make script executable (`chmod +x`)

## 3. Initial Backup

- [x] 3.1 Run backup script manually to create initial backup of current database
- [x] 3.2 Verify backup file exists in `backup/db/` and is valid gzip

## 4. Cron Setup

- [x] 4.1 Add cron job entry for daily execution (e.g., `0 2 * * *` for 2 AM daily)
- [x] 4.2 Document cron setup instructions in a comment at top of backup script
