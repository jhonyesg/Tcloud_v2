## 1. Modify loadStorages function

- [x] 1.1 In `app/resources/views/files/index.blade.php`, modify `loadStorages()` to check `availableStorages.length === 1` after data loads
- [x] 1.2 If exactly one storage, call `enterStorage()` with that storage's id and name

## 2. Verify edge cases

- [ ] 2.1 Test with user who has zero storages - should show "No tienes storages asignados"
- [ ] 2.2 Test with user who has one storage - should auto-enter with no flash
- [ ] 2.3 Test with user who has multiple storages - should show selector
- [ ] 2.4 Test navigation state restoration - saved state should override auto-entry