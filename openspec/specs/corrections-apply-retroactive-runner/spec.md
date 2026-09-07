# corrections-apply-retroactive-runner Specification

## Purpose
Define the lifecycle contract for the asynchronous run that re-applies the approved corrections dictionary to historical transcription segments: how the background PHP worker is launched, how its liveness is verified, how dead workers are surfaced to the admin UI, and how the "stuck" detector distinguishes a worker that died from one that is genuinely processing.

## Requirements

### Requirement: Background worker launches a real PHP CLI binary
The system SHALL launch the background worker with a PHP CLI binary, regardless of the SAPI that served the originating HTTP request. When the request SAPI is not `cli` (e.g. `fpm`), the launcher MUST NOT use `PHP_BINARY` as-is; it MUST resolve to an executable CLI binary (e.g. `/usr/bin/php`) and fall back to a documented location if that path is missing.

#### Scenario: Worker launches under PHP-FPM
- **WHEN** an admin triggers Re-aplicar while the web server runs under PHP-FPM (SAPI `fpm`)
- **THEN** the background process invokes `corrections:apply-run` through a CLI binary, and the artisan command logs its banner to the run log

#### Scenario: Worker launches from a CLI-driven admin task
- **WHEN** the same operation is triggered from a CLI context (SAPI `cli`)
- **THEN** the background process uses the same binary the trigger script used, and behaves identically to the FPM case

#### Scenario: Preferred CLI binary is missing
- **WHEN** the preferred CLI binary is not executable at the documented location
- **THEN** the launcher returns a visible error to the admin within the same request (HTTP 500 with a legible message) instead of silently dispatching a dead command

### Requirement: Liveness ping confirms the worker started
After dispatching the background worker, the originating HTTP request MUST verify that the worker transitioned the run cache from `queued` to `running` within a bounded deadline. If the deadline elapses without that transition, the system MUST mark the run as `error` with a message that points the admin to the run log, and MUST release the active-run pointer so a new run can be launched.

#### Scenario: Worker starts within the deadline
- **WHEN** the background worker writes `status='running'` within the liveness deadline (default 2 seconds)
- **THEN** the originating request returns HTTP 202 with the run id and the UI polls normally

#### Scenario: Worker dies before transitioning to running
- **WHEN** the background process exits before writing `status='running'` (e.g. wrong binary, missing artisan, fatal syntax error)
- **THEN** the liveness ping overwrites the cache to `status='error'` with `error_message` describing the failure, deletes the active-run pointer, and the admin UI receives a visible error in the next poll instead of an indefinite "queued" state

#### Scenario: Run completes before the liveness check
- **WHEN** the run finishes (status `done` or `error`) before the liveness deadline elapses
- **THEN** the originating request does not overwrite the cache; the final state stays as the worker left it

### Requirement: UI surfaces a stuck or orphaned run
The admin UI MUST display a visible warning when a run shows no progress under either of two conditions:
- `status='running'` and the last heartbeat is older than 3 minutes
- `status='queued'` with no `started_at` and `queued_at` older than 60 seconds

The warning MUST include a timestamp of the last activity and MUST offer a way to inspect the run log.

#### Scenario: Running run has stale heartbeat
- **WHEN** a polled run is in `status='running'` and `last_progress_at` is older than 3 minutes
- **THEN** the UI shows the "sin avances desde las HH:MM" warning in both the banner and the modal

#### Scenario: Queued run never started
- **WHEN** a polled run is still in `status='queued'`, has no `started_at`, and `queued_at` is older than 60 seconds
- **THEN** the UI shows the same warning pattern as the stale-heartbeat case, making the orphan state visible

#### Scenario: Run recovers after warning
- **WHEN** a previously stuck or orphaned run advances (worker resumes, or a new run supersedes the orphan)
- **THEN** the UI clears the warning within one polling cycle (2 seconds)

### Requirement: Background-run log is identifiable per caller
Each line appended to the shared background-run log (`/tmp/kilo_artisan_bg.log`) MUST be prefixed with a stable caller tag (e.g. `[corrections:apply]`, `[transcriptor:scan]`, `[transcriptor:settings]`) so an operator can identify which launcher produced which output without inspecting process trees.

#### Scenario: Log inspection is unambiguous
- **WHEN** an admin inspects the shared log while a corrections re-apply run is active
- **THEN** every line emitted during that run is prefixed with `[corrections:apply]`, even when interleaved with output from unrelated background launches
