## Context

See `proposal.md` for the motivation. The current share list loads every row into Alpine.js, computes expiry only in PHP after loading, and has no representation for the physical availability of the related `File`. `StorageSyncService` intentionally refuses destructive purges on missing, empty, partial, or suspicious scans, so a referentially valid `File` can remain after its physical path disappears. Public share metadata is cached as an Eloquent graph, while the password form posts to a route that only accepts GET.

The implementation must preserve the custom session authentication (`session('user_id')`), the existing storage-sync safety guards, the Blade/Alpine no-build frontend, and the `ON DELETE CASCADE` relationship from files to shares and access logs.

## Goals / Non-Goals

**Goals:**

- Move share administration to a paginated, server-side query contract with safe sorting and composable filters.
- Make expiry and physical availability explicit, with `unknown` distinct from confirmed `missing`.
- Support previewable, authorized bulk share cleanup without deleting files or transcriptions.
- Remove sensitive hash data from every share JSON response and unify owner authorization.
- Make public password authentication work for normal HTML clients and keep public metadata fresh.
- Preserve current storage-sync behavior that protects against false mass deletion.

**Non-Goals:**

- No retroactive assignment of expiry dates to existing `expires_at = NULL` rows.
- No soft-delete or undo system for shares in this change; explicit cleanup remains a permanent revocation and cascades its access logs.
- No synchronous physical scan of all files during a normal web request.
- No automatic deletion of `files`, physical media, transcriptions, or storage directories from a share cleanup action.

## Decisions

### 1. Server-side share query contract

`ShareController::index()` becomes the single query surface for `/shares` and `?file_id=...`. It will accept a whitelist of filters: text, permission, expiry state, availability state, storage, creation range, expiry range, and access range. Sort fields are limited to resource name, created date, expiry date, access count, and file size; direction is limited to `asc|desc`. The default page size is bounded and the response contains `data`, pagination metadata, and filter counters.

The query scopes to the current session user. `GET /shares/{id}` uses the same ownership rule. The response is an explicit resource projection and never serializes `password_hash`.

The frontend keeps Alpine state for filters, sort, pagination, selection, loading, and bulk feedback. Every filter or sort change requests the server; it does not assume that the full share collection is in the browser. Column headers use the same clickable sort convention already documented for the Files module.

**Alternative rejected:** retaining client-side filtering. It is simple for 234 current rows but prevents safe “all filtered results” actions, grows the response without bound, and cannot represent physical health without doing filesystem work in the browser request.

### 2. File availability belongs to the file catalog

Add `files.availability_state` (`unknown|available|missing`), `last_verified_at`, and `missing_since_at`, with `unknown` as the safe default. A trustworthy sync or an explicit bounded verification marks a row `available`; a confirmed physical absence on an accessible local storage marks it `missing`. Untrusted scans, missing mount points, refused mass purges, remote providers, and path errors leave the state `unknown` and record no destructive conclusion.

The share list reads this state through `Share -> File`; it does not add a duplicated `file_exists` column to `shares`. A bounded availability verification can target selected shares or the current filter snapshot and never deletes the `File` row. Existing `PruneGuard` behavior remains unchanged.

**Alternative rejected:** calling `file_exists()` for every share on every request. NFS and mounted storage can block or produce transient results, and a web list must not turn a read into an uncontrolled filesystem scan.

### 3. Expiry and cleanup lifecycle

`expires_at` remains nullable, but the UI makes the choice explicit: presets use a configurable `SHARE_DEFAULT_EXPIRY_DAYS` value initialized to 30 days, while “Nunca” sends `NULL`. Existing `NULL` values are preserved. Expiry state is derived in queries and responses, not stored as a second boolean. Date ranges use the application timezone (`America/Bogota`) and half-open intervals to avoid boundary ambiguity.

Bulk cleanup has a preview step and supports explicit IDs or an all-matching filter snapshot. The server re-applies ownership and filters, limits work to shares, deletes in bounded chunks inside a transaction where possible, and returns deleted/skipped/error counts. Cascade deletion removes access logs as it does today.

### 4. Public security and cache freshness

The share model/API uses a safe projection containing `has_password`, never the password hash. `show`, `update`, and `destroy` share one owner/admin authorization rule. Public password authentication receives `POST /s/{token}/authenticate`, validates the password, stores `share_auth_{token}`, and redirects to the existing GET view.

The public view will not cache a serialized `Share` plus `File` graph. It may cache a short-lived token-to-ID lookup, then reload current rows and relations so rename, delete, and availability changes are visible promptly. Share and file mutations invalidate any lookup cache they can identify.

### 5. Database and compatibility

The migration adds the availability columns and checks, an index for expiry and owner/date listing patterns, and makes `share_access_log.accessed_at` non-null after validating existing data. Existing token uniqueness, permission checks, foreign keys, and cascades remain. No data migration assigns expiration dates or deletes stale files.

## Risks / Trade-offs

- [Risk] Availability can remain `unknown` when a storage is unavailable → [Mitigation] never infer `missing` from untrusted scans; expose `unknown` and allow a later bounded verification.
- [Risk] Bulk deletion is irreversible → [Mitigation] require preview, explicit confirmation, ownership scoping, and a result summary; label it as permanent revocation.
- [Risk] A 30-day default may not fit every organization → [Mitigation] keep it configurable and preserve existing permanent links until an explicit cleanup.
- [Risk] Server-side access-count sorting can aggregate logs → [Mitigation] retain the existing access index, add only needed composite indexes, and cap page size.
- [Risk] Removing the full serialized cache graph increases public-link queries → [Mitigation] retain a short-lived token lookup and prefer current correctness over stale metadata.

## Migration Plan

1. Deploy the migration with availability defaulting to `unknown`; no share or file rows are removed.
2. Deploy safe serialization, authorization, public-password routing, and the new query contract.
3. Update sync and bounded verification paths to populate availability facts gradually.
4. Deploy the new `/shares` UI and retain the per-file modal as a compatible caller of the same endpoints.
5. Run targeted security, query, availability, public-link, and bulk-action tests, then manually preview cleanup before executing it.

Rollback is code rollback plus migration rollback only if no availability data must be retained. Existing share rows and `expires_at` values are not rewritten, so application rollback does not require data restoration.

## Open Questions

None blocking. The initial default is configurable at 30 days; changing that value does not require a schema change.
