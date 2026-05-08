## Why

Currently when a user with storage assigned loads the files module, there's a brief "flash" showing the storage selector before the storage list loads. This creates a poor UX experience where users see an unnecessary intermediate state.

## What Changes

- Users with exactly one assigned storage will automatically enter that storage on module load, skipping the storage selector view entirely
- Users with multiple storages or no storages continue to see the selector normally
- The flash/momentary display of "No tienes storages asignados" before data loads is eliminated

## Capabilities

### New Capabilities

- `auto-storage-entry`: Detects single-storage users and auto-navigates to their storage, hiding the selector UI

### Modified Capabilities

- `navigation-state-persistence`: Will save/restore directly to file view mode when user has only one storage

## Impact

- **Frontend**: `files/index.blade.php` - modify `init()` and `loadStorages()` to check storage count and auto-enter if exactly one
- **UX**: Eliminates flash of storage selector for single-storage users