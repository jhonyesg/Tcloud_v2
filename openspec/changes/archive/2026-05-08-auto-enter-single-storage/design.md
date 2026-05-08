## Context

The files module (`files/index.blade.php`) uses Alpine.js for state management. On initialization:

1. `init()` calls `loadStorages()` (async)
2. `viewMode` defaults to `'storages'`
3. Template shows storage selector until data loads
4. When user has only 1 storage, this creates a momentary flash of unnecessary UI

## Goals / Non-Goals

**Goals:**
- Auto-navigate to sole storage when user has exactly 1 assigned storage
- Eliminate UX flash/blank state on module load
- Keep existing behavior for users with 0 or 2+ storages

**Non-Goals:**
- Do not change the storage assignment model
- Do not modify admin storage management UI
- Do not create automatic storage provisioning for users without storages

## Decisions

### Decision 1: Auto-enter logic placement

**Chosen:** Add logic in `loadStorages()` after data is populated

**Rationale:**
- Simple conditional check after fetch completes
- No race conditions with `restoreNavState()`
- Single point of modification

**Alternative:** Modify `init()` to await storage load before setting viewMode

**Why rejected:** More complex, requires restructuring async flow

### Decision 2: When to auto-enter

**Condition:** `availableStorages.length === 1`

This means:
- 0 storages → show selector with "no storages assigned" message (existing behavior)
- 1 storage → auto-enter immediately (new behavior)
- 2+ storages → show selector for user to choose (existing behavior)

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| User gets stuck in storage with no way back | Keep "volver a storages" breadcrumb/button functional |
| Race with `restoreNavState()` | `restoreNavState()` checks for `state.storageId` - if auto-enter sets it first, same result |
| Flash still occurs if storage API is slow | Acceptable - flash duration minimized, not eliminated |

## Open Questions

None - implementation is straightforward.