## 1. Database Migration

- [x] 1.1 Audit existing `files` and `share_access_log` rows for null timestamps before adding constraints; record the safe backfill rule for any invalid `accessed_at` values.
- [x] 1.2 Add `files.availability_state`, `files.last_verified_at` and `files.missing_since_at` with the `unknown|available|missing` check and indexes needed for availability filters.
- [x] 1.3 Add indexes for `shares.expires_at` and owner/date listing patterns, and make `share_access_log.accessed_at` non-null after the audit succeeds.
- [x] 1.4 Add configuration for `SHARE_DEFAULT_EXPIRY_DAYS` with an initial value of 30 and document that existing `expires_at = NULL` rows are not backfilled.

## 2. Share Security and Public Authentication

- [x] 2.1 Add a safe share response projection that exposes `has_password` but never serializes `password_hash` or other secret fields from `Share`.
- [x] 2.2 Apply one owner-or-admin authorization rule to share detail, update, destroy, bulk preview and bulk delete; add regression tests for an authenticated user accessing another user's share.
- [x] 2.3 Add the public `POST /s/{token}/authenticate` route and controller flow, including session authorization, invalid-password responses and redirect back to the public GET view.
- [x] 2.4 Replace serialized public share graphs in cache with a short-lived token lookup plus fresh share/file loading; invalidate the lookup after share and file mutations.
- [x] 2.5 Add feature tests covering password HTML login, header/session compatibility, expired tokens, revoked tokens and absence of password hashes in JSON.

## 3. File Availability State

- [x] 3.1 Update the `File` model and serializers to expose availability state and verification timestamps without adding physical-path status to `shares`.
- [x] 3.2 Update trustworthy storage-sync paths to mark files available only after reliable observation, and leave files unknown for inaccessible, partial, refused or remote scans.
- [x] 3.3 Add a bounded availability-verification service for selected share IDs or a validated filter snapshot; it SHALL never delete files or physical media.
- [x] 3.4 Add the verification endpoint, authorization, result summary and tests for available, missing, unknown, inaccessible-storage and non-local scenarios.
- [x] 3.5 Update public share rendering, preview and download behavior so confirmed missing resources return a clear not-found response without presenting an active working link.

## 4. Server-Side Share Query API

- [x] 4.1 Replace the unbounded `ShareController::index()` collection response with validated pagination, filters, whitelisted sort fields/directions and filter counters while preserving `file_id` lookup compatibility.
- [x] 4.2 Implement expiry-state, availability-state, permission, storage, text and creation/expiry/access date scopes using the application timezone and exclusive upper date bounds.
- [x] 4.3 Add API tests for default pagination, combined filters, sorting, invalid parameters, ownership scoping and safe response fields.
- [x] 4.4 Add bulk preview and bulk delete contracts with re-applied authorization, bounded chunks, explicit result counts and protection against deleting `File` rows.
- [x] 4.5 Add backend tests for successful bulk deletion, empty selections, foreign IDs, stale filter snapshots and partial failures.

## 5. Main Shares Interface

- [x] 5.1 Refactor `resources/views/shares/index.blade.php` Alpine state to use server pagination, query filters, sort state, date ranges, availability badges and counters.
- [x] 5.2 Add clickable sortable headers, custom date controls, quick expiry/availability filters, a clear-filters action and responsive empty/loading/error states.
- [x] 5.3 Add selection across the current filtered result set, bulk preview confirmation, permanent-revocation warning, loading lock and partial-result toast.
- [x] 5.4 Add bounded availability verification from the interface and refresh affected rows without losing the active filter context.
- [x] 5.5 Update the shares guided tour and accessible labels to describe date filters, sorting, availability and bulk actions.

## 6. File Detail and Dashboard Consistency

- [x] 6.1 Update the share panel in `resources/views/files/index.blade.php` to consume the shared bulk contract instead of issuing one DELETE request per selected link.
- [x] 6.2 Preserve permission tabs and per-link editing while adding expiry/availability state and consistent loading/error feedback with the main shares view.
- [x] 6.3 Correct user and admin dashboard share counts so expired and unavailable links are not labeled as active without qualification.

## 7. Lifecycle and Public Regression Coverage

- [x] 7.1 Add tests for the 30-day default, explicit permanent links, existing permanent links, boundary timestamps and all expired public endpoints.
- [x] 7.2 Add tests proving storage-sync safety guards still refuse untrusted mass deletion while availability verification can report missing resources separately.
- [x] 7.3 Add browser-level or feature coverage for sorting, date filtering, select-all filtered results, bulk preview and permanent revocation feedback.

## 8. Verification and Rollout

- [x] 8.1 Run the new migration against a copy of production schema and verify foreign keys, check constraints and indexes without changing existing share/file counts.
- [x] 8.2 Run `php artisan route:list`, targeted PHPUnit suites and the full available PHPUnit command; fix the existing Share model test bootstrap errors before marking validation complete.
- [x] 8.3 Manually verify a permanent link, an expired link, a confirmed missing file, an unknown storage, a password-protected link and a bulk cleanup preview before executing any permanent deletion.
- [x] 8.4 Document rollback: revert application code, preserve existing shares, and only reverse availability columns/indexes when no collected verification state must be retained.
