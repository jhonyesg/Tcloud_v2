## ADDED Requirements

### Requirement: Storage Provider Management
The system SHALL allow admins to manage external storage providers (folders).

#### Scenario: Admin creates storage provider
- **WHEN** admin creates storage provider with name, type (local/s3), and configuration
- **THEN** storage_providers record is created and storage is ready for assignment

#### Scenario: Admin lists storage providers
- **WHEN** admin requests list of all storage providers
- **THEN** system returns list with name, type, enabled status, file count

#### Scenario: Admin updates storage provider
- **WHEN** admin updates storage provider configuration
- **THEN** storage_providers record is modified

#### Scenario: Admin deletes storage provider
- **WHEN** admin deletes storage provider
- **THEN** storage is unlinked from all users, files remain but become orphaned

### Requirement: Storage Provider Types
The system SHALL support local filesystem and S3-compatible storage types.

#### Scenario: Local storage type
- **WHEN** admin creates storage with type 'local' and base_path
- **THEN** files are stored in the specified local directory

#### Scenario: S3 storage type
- **WHEN** admin creates storage with type 's3' and credentials
- **THEN** files are stored in the configured S3 bucket

### Requirement: Storage Provider Connection Test
The system SHALL verify storage connectivity before saving.

#### Scenario: Test local storage connection
- **WHEN** admin tests local storage provider connection
- **THEN** system verifies base_path exists and is writable

#### Scenario: Test S3 connection
- **WHEN** admin tests S3 storage provider connection
- **THEN** system attempts to list buckets and returns success/error