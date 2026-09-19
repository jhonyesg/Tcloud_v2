## Purpose

Notifica a los creadores de shares de folder con permisos destructivos (`write` o `full`) cuando la canonicalización del file_id (PR 1) cambia el comportamiento de delete de no-op a destructivo real, para que el operador esté consciente antes de que un visitante use el share para borrar archivos en disco.

## Requirements

### Requirement: Notification command identifies recently repointed write/full shares

When the operator runs `php artisan shares:notify-repointed [--days=N]` (default 7), the system MUST identify all folder shares whose `file_id` was updated in the last N days via a `repoint_share` row in `file_mirror_audit_log` AND whose `permissions IN ('write','full')`.

#### Scenario: Default window catches recent repoints
- **WHEN** the operator runs `shares:notify-repointed` without flags
- **THEN** the system MUST scan `file_mirror_audit_log` for `action='repoint_share'` with `created_at > now() - interval '7 days'`
- **AND** MUST filter shares whose `permissions IN ('write','full')`
- **AND** MUST group by creator (one notification per creator, not per share)

#### Scenario: Custom window extends the lookback
- **WHEN** the operator runs `shares:notify-repointed --days=30`
- **THEN** the system MUST use `interval '30 days'` instead of the default

#### Scenario: Dry-run reports without sending
- **WHEN** the operator runs `shares:notify-repointed --dry-run`
- **THEN** the system MUST list each recipient with their share count and email
- **AND** MUST NOT send any email

### Requirement: Notification email content is informative

Each notification email MUST include the share token (truncated), the affected folder name, the original mirror id, the new canonical id, and an explicit warning about destructive delete.

#### Scenario: Email contains action context
- **WHEN** a creator receives a notification for N repointed shares
- **THEN** the email body MUST list each share's token (first 14 chars), folder name, and old/new file_id
- **AND** MUST contain the subject line "Tu share [token...] ahora apunta al folder canónico"
- **AND** MUST contain the phrase "delete operations through this share are now destructive on disk" in Spanish

### Requirement: Notification skips creators without email

When a creator's `users.email` is NULL or empty, the system MUST skip that creator and log a warning, not fail the entire notification batch.

#### Scenario: Creator without email is logged and skipped
- **WHEN** a repointed share's `created_by` user has `email IS NULL OR email = ''`
- **THEN** the system MUST log "skipped: user <id> has no email"
- **AND** MUST NOT include that share in any sent notification
- **AND** MUST NOT raise an exception

### Requirement: Notification is idempotent within a window

Re-running `shares:notify-repointed` for the same window MUST NOT send duplicate emails to the same creator for the same shares.

#### Scenario: Second run within window is no-op
- **WHEN** the operator runs `shares:notify-repointed` twice within the same window
- **THEN** the second run MUST detect previously-notified shares via a `share_notification_log` table
- **AND** MUST NOT re-send the email
- **AND** MUST report "0 pending notifications"