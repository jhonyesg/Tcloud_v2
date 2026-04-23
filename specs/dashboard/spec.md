## ADDED Requirements

### Requirement: Admin Dashboard
The system SHALL provide an admin dashboard with system-wide summary statistics.

#### Scenario: Admin views dashboard
- **WHEN** admin requests dashboard data
- **THEN** system returns: total users count, total storage providers count, total files count, total storage used (bytes), recent shares count, active share links count

### Requirement: User Dashboard
The system SHALL provide a standard user dashboard with personal summary statistics.

#### Scenario: User views dashboard
- **WHEN** standard user requests dashboard data
- **THEN** system returns: assigned storages with usage (quota/used), recent files (last 10), shared files received count, user's active share links count

### Requirement: Dashboard Data Access
The system SHALL enforce that users can only access their role's dashboard.

#### Scenario: Standard user accesses admin dashboard
- **WHEN** standard user requests admin dashboard endpoint
- **THEN** system returns 403 Forbidden error

#### Scenario: Admin accesses user dashboard
- **WHEN** admin requests user dashboard
- **THEN** system returns user dashboard data (admin can view any user's data)