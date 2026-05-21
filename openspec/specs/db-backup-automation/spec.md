### Requirement: Daily automated database backup
The system SHALL execute a PostgreSQL backup of the `tcloudstorage` database daily via a shell script.

#### Scenario: Successful backup execution
- **WHEN** the backup script `scripts/backup-db.sh` is executed
- **THEN** a gzip-compressed SQL dump file is created in `backups/` with the naming pattern `tcloudstorage_YYYYMMDD_HHMMSS.sql.gz`

#### Scenario: Backup uses correct database credentials
- **WHEN** the backup script connects to PostgreSQL
- **THEN** it SHALL use container `tcloud_postgres`, database `tcloudstorage`, user `cloud`, and the configured password from the docker-compose environment

### Requirement: Backup file compression
The system SHALL compress all backup files using gzip to minimize disk usage.

#### Scenario: Compressed output
- **WHEN** a backup is generated
- **THEN** the output file SHALL have a `.gz` extension and be gzip-compressed

### Requirement: Automatic retention cleanup
The system SHALL automatically delete backup files older than 20 days.

#### Scenario: Old backups are removed
- **WHEN** the backup script runs
- **THEN** all files in `backups/` matching `tcloudstorage_*.sql.gz` with a modification time older than 20 days SHALL be deleted

#### Scenario: Recent backups are preserved
- **WHEN** the backup script runs
- **THEN** backup files newer than 20 days SHALL NOT be deleted

### Requirement: Backup directory structure
The system SHALL store all backups in a dedicated directory at the project root.

#### Scenario: Directory creation
- **WHEN** the backup script runs and `backups/` does not exist
- **THEN** the script SHALL create the `backups/` directory before writing the backup

### Requirement: Manual execution support
The backup script SHALL be executable manually by an administrator at any time.

#### Scenario: Manual backup run
- **WHEN** an administrator runs `bash scripts/backup-db.sh`
- **THEN** a new backup file SHALL be created immediately in `backups/`

#### Scenario: Script is idempotent
- **WHEN** the backup script is run multiple times in quick succession
- **THEN** each run SHALL produce a separate uniquely-named backup file without overwriting existing ones
