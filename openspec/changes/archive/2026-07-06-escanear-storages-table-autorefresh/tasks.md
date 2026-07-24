## 1. Frontend — auto-refresh + visual indicator

- [x] 1.1 In `app/resources/views/ia/api-transcriptor/index.blade.php` `pollBatch()`, after assigning `this.batchProgress = data`, add a branch that, when `data.status ∈ {running, starting}`, increments a `batchTableRefreshTick` counter and calls `this.load()` + `this.loadStats()` every 2nd tick.
- [x] 1.2 In the same `pollBatch()` done/error/not_found branch, reset `batchTableRefreshTick = 0` alongside the existing `this.load()` + `this.loadStats()` calls.
- [x] 1.3 In `stopBatchPolling()`, reset `batchTableRefreshTick = 0` so the next batch starts at tick 0.
- [x] 1.4 In the Pendientes toolbar (next to the existing manual refresh button at line ~518), add an inline pill `<span x-show="batchRunning" x-transition.opacity>` with `bg-brand-50 border-brand-200` styling, a `fa-circle-notch fa-spin` icon, and label "Sincronizando Pendientes".

## 2. Verification

- [x] 2.1 `php -l app/resources/views/ia/api-transcriptor/index.blade.php` → "No syntax errors detected".
- [x] 2.2 `php artisan view:clear` → "Compiled views cleared successfully".
- [ ] 2.3 Manual smoke test (browser) — **PENDING USER VERIFICATION**: hard refresh `/ia/api-transcriptor`, click "Escanear storages", choose batch size 20, click "Iniciar". Confirm the "🔄 Sincronizando Pendientes" pill appears next to the refresh button and the Pendientes table updates every ~4 seconds while the batch runs.