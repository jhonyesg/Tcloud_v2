## ADDED Requirements

### Requirement: Create Share Link
The system SHALL allow users to create share links based on their permissions and can_create_shares flag.

#### Scenario: Create share with can_create_shares=true and full permission
- **WHEN** user has can_create_shares=true on storage AND has full permission on file
- **THEN** share is created with unique token

#### Scenario: Create share with can_create_shares=false
- **WHEN** user attempts to create share but can_create_shares=false on their storage assignment
- **THEN** system returns 403 Forbidden error

#### Scenario: Create share with read permission only
- **WHEN** user has can_create_shares=true but only read permission on file
- **THEN** share is created with read-only permission (user cannot give more than they have)

#### Scenario: Create share for owned file
- **WHEN** user creates share for file they own
- **THEN** share is created successfully

#### Scenario: Cannot share file received from another user
- **WHEN** user attempts to create share for file shared with them by another user
- **THEN** system returns 403 Forbidden error

#### Scenario: Admin can share any file
- **WHEN** admin creates share for any file in system
- **THEN** share is created successfully regardless of ownership

#### Scenario: Create password-protected share
- **WHEN** user creates share with password
- **THEN** password is bcrypt hashed before storage

#### Scenario: Create expiring share
- **WHEN** user creates share with expiration date
- **THEN** share expires at specified datetime

### Requirement: Share Permissions
The system SHALL support granular permission levels for shares.

#### Scenario: Read permission
- **WHEN** share is created with 'read' permission
- **THEN** recipient can view and download files, cannot upload or delete

#### Scenario: Write permission
- **WHEN** share is created with 'write' permission
- **THEN** recipient can view, download, and upload files to the shared folder

#### Scenario: Upload permission
- **WHEN** share is created with 'upload' permission
- **THEN** recipient can view and upload files but cannot delete or modify existing files

#### Scenario: Full permission
- **WHEN** share is created with 'full' permission
- **THEN** recipient has complete control: view, download, upload, delete, and modify

### Requirement: Share Expiration
The system SHALL enforce expiration dates on share links.

#### Scenario: Set share expiration
- **WHEN** user creates share with expires_at datetime
- **THEN** share is accessible until expiration date

#### Scenario: Access expired share
- **WHEN** user attempts to access share after expiration
- **THEN** system returns 410 Gone error

#### Scenario: Create permanent share
- **WHEN** user creates share without expiration
- **THEN** share remains valid indefinitely

### Requirement: Access Shared Content
The system SHALL provide public access to shared content via tokens.

#### Scenario: Access valid share without password
- **WHEN** public user accesses share endpoint with valid token and no password
- **THEN** system returns file/folder contents

#### Scenario: Access password-protected share
- **WHEN** public user provides correct password for protected share
- **THEN** system grants access to shared content

#### Scenario: Access without password
- **WHEN** public user accesses password-protected share without password
- **THEN** system returns 401 Unauthorized error

#### Scenario: Access share with wrong password
- **WHEN** public user provides incorrect password
- **THEN** system returns 401 Unauthorized error

### Requirement: Modify Share
The system SHALL allow share owners to modify share settings.

#### Scenario: Update share expiration
- **WHEN** share owner updates expiration date
- **THEN** share expires_at is modified

#### Scenario: Change share permissions
- **WHEN** share owner changes permission level
- **THEN** share permissions are updated

#### Scenario: Add password to existing share
- **WHEN** share owner adds password to unprotected share
- **THEN** password_hash is set, access now requires password

#### Scenario: Remove password from share
- **WHEN** share owner removes password from protected share
- **THEN** password_hash is cleared, access no longer requires password

### Requirement: List User Shares
The system SHALL allow users to view all shares they created.

#### Scenario: List owned shares
- **WHEN** user requests list of their shares
- **THEN** system returns all shares created by user with file info, expiration, permissions

### Requirement: Revoke Share
The system SHALL allow users to delete shares they created.

#### Scenario: Delete share
- **WHEN** share owner deletes a share
- **THEN** share token becomes invalid, access is denied

#### Scenario: Delete share revokes access immediately
- **WHEN** share is deleted while being accessed
- **THEN** subsequent access attempts return 404 Not Found