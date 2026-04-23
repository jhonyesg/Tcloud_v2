## ADDED Requirements

### Requirement: Multi-Level Login
The system SHALL support two user roles: admin and standard user, each with different access levels.

#### Scenario: Admin login
- **WHEN** user with admin role logs in
- **THEN** system returns session token with admin role and redirects to admin dashboard

#### Scenario: Standard user login
- **WHEN** user with standard role logs in
- **THEN** system returns session token with user role and redirects to user dashboard

#### Scenario: Invalid credentials
- **WHEN** user submits invalid email or password
- **THEN** system returns 401 Unauthorized error

### Requirement: Session Management
The system SHALL manage user sessions using Redis with 7-day TTL.

#### Scenario: Session creation on login
- **WHEN** user logs in successfully
- **THEN** system creates session in Redis with user_id, role, created_at, expires_at

#### Scenario: Session validation on protected routes
- **WHEN** user accesses protected endpoint
- **THEN** system validates session from Redis and allows/denies access based on role

#### Scenario: Session expiration
- **WHEN** session TTL expires
- **THEN** session is automatically removed from Redis

### Requirement: Role-Based Access Control
The system SHALL enforce access control based on user role.

#### Scenario: Admin accesses admin endpoints
- **WHEN** admin user accesses user management or storage management endpoints
- **THEN** access is granted

#### Scenario: Standard user accesses admin endpoints
- **WHEN** standard user attempts to access admin-only endpoints
- **THEN** system returns 403 Forbidden error

#### Scenario: User accesses own resources
- **WHEN** standard user accesses files in their assigned storages
- **THEN** access is granted

### Requirement: Password Security
The system SHALL store passwords using bcrypt hashing with cost factor 12.

#### Scenario: Password hashing
- **WHEN** user creates account or changes password
- **THEN** password is hashed with bcrypt before storing

#### Scenario: Password verification
- **WHEN** user attempts login
- **THEN** system compares provided password against stored bcrypt hash