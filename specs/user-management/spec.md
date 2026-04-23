## ADDED Requirements

### Requirement: User CRUD Operations
The system SHALL provide create, read, update, and delete operations for user accounts (admin only).

#### Scenario: Admin creates new user with quota
- **WHEN** admin creates a new user with email, password, role, and personal_quota_bytes
- **THEN** user record is created with unique ID, hashed password, role, and personal_quota_bytes (0 = unlimited)

#### Scenario: Admin lists all users
- **WHEN** admin requests list of all users
- **THEN** system returns paginated list of users with email, role, created_at, personal_quota, personal_used_bytes

#### Scenario: Admin updates user
- **WHEN** admin updates user information (email, role, personal_quota_bytes)
- **THEN** user record is modified with updated values

#### Scenario: Admin deletes user
- **WHEN** admin deletes a user
- **THEN** user record is removed, user's own files remain orphaned, user_storages are deleted, shares are deleted

### Requirement: Personal Quota Management
The system SHALL allow admins to set personal quota for users and track usage.

#### Scenario: Set unlimited quota
- **WHEN** admin creates user with personal_quota_bytes = 0
- **THEN** user has no quota limit for their own files

#### Scenario: Set limited quota
- **WHEN** admin creates user with personal_quota_bytes = 10737418240 (10GB)
- **THEN** user can only upload 10GB of personal files

#### Scenario: Update user quota
- **WHEN** admin updates user's personal_quota_bytes
- **THEN** user's quota is updated immediately

### Requirement: Role Assignment
The system SHALL support assigning admin or standard role to users.

#### Scenario: Assign admin role
- **WHEN** admin creates user with admin role
- **THEN** user has full system access, can manage all files and users

#### Scenario: Assign standard role
- **WHEN** admin creates user with standard role
- **THEN** user has limited access to assigned storages only, based on storage permissions

### Requirement: User Self-Profile
The system SHALL allow users to view their assigned storages and personal quota.

#### Scenario: User views own profile
- **WHEN** standard user requests their profile
- **THEN** system returns user info (email, role, personal_quota, personal_used_bytes, assigned storages with permissions)

#### Scenario: User changes password
- **WHEN** standard user changes their password
- **THEN** new password is hashed and stored