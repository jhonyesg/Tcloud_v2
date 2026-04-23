## ADDED Requirements

### Requirement: Assign Storage with Permissions
The system SHALL allow admins to assign storage providers to users with granular permissions.

#### Scenario: Assign storage with read permission
- **WHEN** admin assigns storage to user with permissions='read'
- **THEN** user can view and download files but cannot upload, create folders, delete, or rename

#### Scenario: Assign storage with write permission
- **WHEN** admin assigns storage to user with permissions='write'
- **THEN** user can view, download, upload, create subfolders but cannot delete or rename

#### Scenario: Assign storage with full permission
- **WHEN** admin assigns storage to user with permissions='full'
- **THEN** user can view, download, upload, create, delete, and rename files in that storage

#### Scenario: Same storage assigned to multiple users with different permissions
- **WHEN** admin assigns same storage to multiple users with different permission levels
- **THEN** each user has the specific permissions assigned to them

### Requirement: Enable/Disable Share Creation
The system SHALL allow admins to control whether a user can create public share links for files in an assigned storage.

#### Scenario: Enable share creation
- **WHEN** admin assigns storage with can_create_shares=true
- **THEN** user can create public share links for files they have access to

#### Scenario: Disable share creation
- **WHEN** admin assigns storage with can_create_shares=false
- **THEN** user cannot create public share links for files in that storage

#### Scenario: Update share creation permission
- **WHEN** admin updates can_create_shares flag on existing assignment
- **THEN** user's ability to create shares is updated immediately

### Requirement: List User's Assigned Storages with Permissions
The system SHALL return user's assigned storages with their permission levels.

#### Scenario: List assigned storages
- **WHEN** user or admin requests user's assigned storages
- **THEN** system returns list with storage info and user's permissions (read/write/full, can_create_shares)

### Requirement: Remove Storage Assignment
The system SHALL allow admins to remove storage assignments from users.

#### Scenario: Remove storage from user
- **WHEN** admin removes storage assignment from user
- **THEN** user_storages record is deleted, user's files in that storage remain but inaccessible

### Requirement: Enforce Permissions on File Operations
The system SHALL validate user's permissions before allowing file operations.

#### Scenario: Allow upload with write permission
- **WHEN** user with write permission uploads file to assigned storage
- **THEN** upload is allowed

#### Scenario: Deny delete with write permission
- **WHEN** user with write permission attempts to delete file
- **THEN** system returns 403 Forbidden error

#### Scenario: Deny share creation when disabled
- **WHEN** user with can_create_shares=false attempts to create share
- **THEN** system returns 403 Forbidden error

#### Scenario: Allow delete with full permission
- **WHEN** user with full permission deletes file they created (not original owner)
- **THEN** file is deleted