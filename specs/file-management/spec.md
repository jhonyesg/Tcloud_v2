## ADDED Requirements

### Requirement: File Listing and Navigation
The system SHALL provide folder navigation based on user's assigned storages and permissions.

#### Scenario: List assigned storage files
- **WHEN** user requests files with storage_id where they have access
- **THEN** system returns files and folders in that storage based on user's permission level

#### Scenario: Navigate into folder
- **WHEN** user requests files with a folder's parent_id
- **THEN** system returns contents of that folder if user has at least read permission

#### Scenario: Deny access to unassigned storage
- **WHEN** user requests files in storage not assigned to them
- **THEN** system returns 403 Forbidden error

### Requirement: File Upload Based on Permissions
The system SHALL allow file upload only if user has write or full permission on the storage.

#### Scenario: Upload with write permission
- **WHEN** user with write permission uploads file to assigned storage
- **THEN** file is uploaded and stored

#### Scenario: Upload with full permission
- **WHEN** user with full permission uploads file to assigned storage
- **THEN** file is uploaded and stored

#### Scenario: Upload without write permission
- **WHEN** user with read-only permission uploads file
- **THEN** system returns 403 Forbidden error

#### Scenario: Upload to own space with personal quota
- **WHEN** user uploads file to their personal space (owner_id = user_id)
- **THEN** personal quota is checked and consumed

#### Scenario: Upload to shared folder (uses original owner's quota)
- **WHEN** user uploads file to folder where they have write/full permission
- **THEN** file is owned by the storage owner, not the uploader

### Requirement: File Download
The system SHALL allow file download if user has at least read permission.

#### Scenario: Download with read permission
- **WHEN** user with read permission downloads file
- **THEN** system streams file with appropriate Content-Disposition header

#### Scenario: Download with write/full permission
- **WHEN** user with write or full permission downloads file
- **THEN** system streams file

#### Scenario: Download without permission
- **WHEN** user attempts to download file they have no access to
- **THEN** system returns 403 Forbidden error

### Requirement: File Deletion
The system SHALL allow file deletion only if user has full permission AND is the file owner.

#### Scenario: Delete owned file with full permission
- **WHEN** user with full permission deletes file they own
- **THEN** file is deleted, storage provider file is removed, used_bytes updated

#### Scenario: Delete file created by user in shared folder (not owner)
- **WHEN** user with full permission deletes file they created (not original owner)
- **THEN** file is deleted

#### Scenario: Cannot delete original owner's file
- **WHEN** user with full permission attempts to delete file owned by another user
- **THEN** system returns 403 Forbidden error

#### Scenario: Delete without full permission
- **WHEN** user with read or write permission attempts to delete file
- **THEN** system returns 403 Forbidden error

### Requirement: Folder Creation
The system SHALL allow folder creation only if user has write or full permission.

#### Scenario: Create folder with write permission
- **WHEN** user with write permission creates folder
- **THEN** folder is created with owner_id = original storage owner

#### Scenario: Create folder with full permission
- **WHEN** user with full permission creates folder
- **THEN** folder is created

#### Scenario: Create folder without write permission
- **WHEN** user with read permission attempts to create folder
- **THEN** system returns 403 Forbidden error

#### Scenario: Prevent duplicate folder names
- **WHEN** user creates folder with name that already exists in same parent
- **THEN** system returns 409 Conflict error

### Requirement: File Rename
The system SHALL allow file rename only if user has full permission AND is the file owner.

#### Scenario: Rename owned file with full permission
- **WHEN** user with full permission renames file they own
- **THEN** file name is updated

#### Scenario: Cannot rename file not owned
- **WHEN** user with full permission attempts to rename file owned by another user
- **THEN** system returns 403 Forbidden error

#### Scenario: Rename without full permission
- **WHEN** user with read or write permission attempts to rename file
- **THEN** system returns 403 Forbidden error

### Requirement: File Preview
The system SHALL allow file preview if user has at least read permission.

#### Scenario: Preview image
- **WHEN** user with read/write/full permission requests preview for image
- **THEN** system returns image with appropriate Content-Type header

#### Scenario: Preview PDF
- **WHEN** user with read/write/full permission requests preview for PDF
- **THEN** system returns PDF with inline Content-Disposition header

#### Scenario: Play audio
- **WHEN** user with read/write/full permission requests preview for audio
- **THEN** system streams audio with HTML5 audio player

#### Scenario: Play video
- **WHEN** user with read/write/full permission requests preview for video
- **THEN** system streams video with HTML5 video player and adaptive streaming

#### Scenario: Preview without permission
- **WHEN** user without read permission attempts to preview file
- **THEN** system returns 403 Forbidden error