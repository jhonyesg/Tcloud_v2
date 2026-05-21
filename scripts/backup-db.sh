#!/bin/bash
# =============================================================================
# TCloud PostgreSQL Backup Script
# =============================================================================
# Crontab setup (daily at 2 AM):
#   0 2 * * * /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/scripts/backup-db.sh
#
# To add the cron job, run:
#   crontab -e
#   # Then add the line above
#
# Manual execution:
#   bash /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/scripts/backup-db.sh
# =============================================================================

set -euo pipefail

# Configuration
CONTAINER="tcloud_postgres"
DATABASE="tcloudstorage"
DB_USER="cloud"
BACKUP_DIR="/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/backup/db"
RETENTION_DAYS=20
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
FILENAME="${DATABASE}_${TIMESTAMP}.sql.gz"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Run pg_dump inside the Docker container and compress with gzip
echo "[$(date)] Starting backup: ${FILENAME}"
docker exec "$CONTAINER" pg_dump -U "$DB_USER" -d "$DATABASE" --no-owner --no-acl | gzip > "${BACKUP_DIR}/${FILENAME}"

# Check if backup was successful
if [ $? -eq 0 ] && [ -s "${BACKUP_DIR}/${FILENAME}" ]; then
    SIZE=$(du -h "${BACKUP_DIR}/${FILENAME}" | cut -f1)
    echo "[$(date)] Backup completed: ${FILENAME} (${SIZE})"
else
    echo "[$(date)] ERROR: Backup failed!"
    rm -f "${BACKUP_DIR}/${FILENAME}"
    exit 1
fi

# Delete backups older than RETENTION_DAYS
DELETED=$(find "$BACKUP_DIR" -name "${DATABASE}_*.sql.gz" -type f -mtime +${RETENTION_DAYS} -print -delete | wc -l)
if [ "$DELETED" -gt 0 ]; then
    echo "[$(date)] Cleaned up ${DELETED} old backup(s) (>${RETENTION_DAYS} days)"
fi

# Show current backup count and total size
COUNT=$(find "$BACKUP_DIR" -name "${DATABASE}_*.sql.gz" -type f | wc -l)
TOTAL_SIZE=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)
echo "[$(date)] Current backups: ${COUNT} files, total size: ${TOTAL_SIZE}"
echo "[$(date)] Backup process finished"
