## Context

See `proposal.md` for motivation. The reported 500 surfaces because PHP raises `E_WARNING` from `mkdir()` (permission denied) which Laravel's `HandleExceptions` converts into `ErrorException` BEFORE the `if (!mkdir(...))` guard at `FileController.php:303-305` ever runs — the controller then dies before producing the intended `{"error":"No se pudo crear el directorio"}` JSON and the client receives `{"message":"Server Error"}` with `APP_DEBUG=false`. The Mis Archivos Alpine component (`files/index.blade.php`) already has a working `showToast(msg, type, ms)` helper at line 1071 used 16+ times in the same file, but two error branches (`createFolder` line 908, `saveImgRotation` line 858) still call `alert()`. The same `mkdir()` bug exists unguarded in `PublicShareController.php:531` (sibling endpoint, different surface).

## Goals / Non-Goals

**Goals:**
- Convert `mkdir()` warnings into catchable exceptions, then translate them into JSON `{"error":"..."}` responses with `Log::error` for diagnosis.
- Pre-check `is_writable(dirname())` so we fail fast and avoid relying on the `mkdir()` return value at all.
- Surface backend errors to operators as Alpine toasts (no `alert()`) using the local `showToast` helper that already exists in the same component.
- Document the operational fix (filesystem ownership alignment) as a runbook in AGENTS.md so the same 500 doesn't recur from a fresh storage mount.

**Non-Goals:**
- Consolidating the toast infrastructure globally (out of scope, see `ui_alerts_prefer_toasts` AGENTS.md constraint and the 55 other alerts inventoried during exploration).
- Touching the upload path in `FileController.php:432`, the transcriptor pipeline, or any other `mkdir()` callsites outside Mis Archivos.
- Adding a new Alpine store or `window.showToast` global helper.

## Decisions

### Decision 1: `mkdir()` wrapped in try/catch instead of suppressing warnings

**Choice**: Wrap `mkdir()` in `try { ... } catch (\Throwable $e) { Log::error(...); return 500 JSON; }` and also add a pre-check `if (!is_writable(dirname($physicalPath)))`.

**Rationale**: Laravel's `HandleExceptions` converts ALL `E_WARNING` (including those from `mkdir`) into `ErrorException` regardless of the return value. The existing `if (!mkdir(...))` guard at lines 303-305 is dead code in the permission-denied case because the exception fires before `mkdir()` returns. The pre-check `is_writable()` short-circuits the common case cleanly. The try/catch is the belt-and-suspenders for ENOSPC, EACCES on read-only filesystems, etc.

**Alternatives considered**:
- *Use `@mkdir()` to suppress warnings.* Rejected: hides real bugs (disk full) and `mkdir()` STILL returns `false`, the guard would work, but logs would be empty. Worse diagnosis.
- *Pre-check only, no try/catch.* Rejected: doesn't handle ENOSPC, EIO, EROFS. We want a single code path for all filesystem failures.
- *Move mkdir to a service class.* Rejected: out of proportion for a 500 → 500-with-message change. The current code structure stays.

### Decision 2: Reuse `showToast(msg, type, ms)` already in the same Alpine component

**Choice**: Replace `alert('Error al crear carpeta: ' + (data.error || res.status))` at `files/index.blade.php:908` with `this.showToast('Error al crear carpeta: ' + (data.error || data.message || ('HTTP ' + res.status)), 'error', 5000)`. Same pattern at line 858 for image rotation.

**Rationale**: The helper at line 1071 is already used 16+ times in this file (e.g., line 412, 433, 472, 523, 701, 1038, 1095, 1109, 1109, 1126, 1202, 1247, 1368, 1370). The toast markup at line 4403-4414 already exists. Zero infrastructure work. The expanded fallback chain (`data.error || data.message || ('HTTP ' + res.status)`) is the missing piece that would have made the current screenshot show "No tienes permiso para..." instead of "500".

**Alternatives considered**:
- *Promote a global `window.showToast` in `layouts/app.blade.php`.* Rejected: scope creep. That's the "toast consolidation" change (out of scope per proposal).
- *Add a `<x-toast>` Blade component.* Rejected: not idiomatic in this app (existing pattern is local Alpine state, not components).

### Decision 3: Server-side permission check stays; just log the rejected request at info-level

**Choice**: `FileController.php:268` already calls `$user->hasStoragePermission($request->storage_id, 'write')` and returns 403 with `{"error":"Write permission required"}`. The 409 branch at lines 283-290 already exists. The fix does NOT change those branches — only the message text in the 403 to match the spec (`"No tienes permiso para crear carpetas en este almacenamiento"`) and adds info-level logging for the rejection.

**Rationale**: The 403 / 409 paths are working correctly today. The ONLY missing path is the 500 (mkdir failure) → JSON-error translation. Changing the 403 message is a one-line touch-up so the toast shows the canonical text instead of the developer-y `"Write permission required"`.

**Alternatives considered**:
- *Rename `Write permission required` to canonical Spanish.* Required to satisfy the spec scenario text. Trivial change, included.

### Decision 4: PublicShareController mkdir() gets the same guard

**Choice**: Apply the same `is_writable()` pre-check + `try/catch (\Throwable $e)` pattern to `PublicShareController.php:531`. Add a `Log::warning('share.folder_create_failed', [...])` for diagnosis.

**Rationale**: Same latent bug, same root cause (mkdir warning → ErrorException). Public shares via token are also operator-facing and need the same UX. Without this fix, a public share creation on a root-owned storage 500s identically with `{"message":"Server Error"}`.

**Alternatives considered**:
- *Leave PublicShareController alone.* Rejected: the spec for `file-permission-checks` explicitly calls out `PublicShareController::createFolder` as a sibling that must have consistent error semantics. Leaving it would create the same 500 in a different surface.

## Risks / Trade-offs

- [R1 — Backend change risks masking real config bugs] The `try/catch` over `mkdir()` can hide genuine issues (NFS mount failure, wrong base_path in `storage_providers`, etc.). → Mitigation: `Log::error` includes `storage_id`, full `physicalPath`, the original `Throwable->getMessage()`, and errno. The 500 response is descriptive (`"No se pudo crear el directorio en el almacenamiento: <motivo>"`). Operators get both a structured log entry AND a readable toast.

- [R2 — Filesystem chown could break admin scripts] The operational fix (`chown -R www:www /www/wwwroot/data.mediaserver.com.co/Tcloud`) changes ownership of large directories. → Mitigation: Runbook in AGENTS.md offers TWO paths: (a) full chown to `www:www`, or (b) add `www` to a `storage-admins` group and `chmod g+w` recursively. Path (b) preserves `root:root` ownership for audit. The decision is left to the operator.

- [R3 — Toast conversion changes a long-standing UX habit] The operator has seen `alert()` for years. → Mitigation: The toast is visually equivalent (red background, bottom-center, same text), shows the same message AND more detail (it will now include the actual server-side reason, not just "500"), and disappears automatically so it doesn't block work.

- [R4 — The `hasStoragePermission` check at FileController.php:268 uses raw permission strings, not a `write|full` set] Re-reading the model: `permissions` is one of `'read'|'write'|'full'|'upload'|'none'`. The current check `!$user->hasStoragePermission($id, 'write')` correctly allows `'write'` AND `'full'` because the helper checks `>=`. Verified by reading the model contract — no behavior change needed.

## Migration Plan

### Deployment

Single PR with two commits (or one merge commit if squash-merged):
1. **fix(backend): defensive mkdir() in Mis Archivos folder creation** — `FileController.php`, `PublicShareController.php`.
2. **fix(frontend): replace alert() with toast in Mis Archivos** — `files/index.blade.php`.

### Operational follow-up (runbook in AGENTS.md)

After the deploy, the operator runs the runbook to align storage ownership:
```bash
ls -la /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
# If root:root and write fails, pick one:
chown -R www:www /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
# OR
groupadd storage-admins && usermod -aG storage-admins www
chmod -R g+w /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
```

The defensive code fix makes BOTH paths safe: even if the chown doesn't run, the user sees a meaningful 500 with a clear error message instead of the opaque `"Server Error"` they see today.

### Rollback

```bash
git revert <merge-commit>
systemctl reload php84-php-fpm
# No DB migration to revert; no workers to restart; no Redis keys to clean.
```

If the chown was applied and needs reversal:
```bash
chown -R root:root /www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_I/
```

## Open Questions

None. All decisions are made within the scope of the proposal and the spec requirements.
