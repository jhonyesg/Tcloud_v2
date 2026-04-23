## ADDED Requirements

### Requirement: OpenSpec artifacts at project root
The system SHALL store OpenSpec change artifacts (design.md, proposal.md, tasks.md, specs/) at the project root level instead of within `openspec/changes/<change>/` subdirectory.

#### Scenario: Restructure existing change
- **WHEN** user requests moving cloud-storage-system to project root
- **THEN** all artifacts are relocated to project root while preserving directory structure

### Requirement: Configurable artifact base path
The OpenSpec configuration SHALL support a configurable base path for artifact storage, defaulting to project root.

#### Scenario: Default base path
- **WHEN** OpenSpec is initialized without custom path configuration
- **THEN** changes are stored at `./<change-name>/` at project root level

### Requirement: Backward compatibility with existing changes
The system SHALL maintain compatibility with existing changes stored in `openspec/changes/` during transition period.

#### Scenario: Mixed storage locations
- **WHEN** some changes exist at root and others in openspec/changes/
- **THEN** OpenSpec commands work correctly for both locations