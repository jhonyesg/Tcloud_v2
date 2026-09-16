## Why

El dashboard admin se ha vuelto lento porque cada carga ejecuta agregados globales sobre tablas grandes. El más costoso es `File::sum('size')` sobre 1.88M filas (~1.36 s medido con `EXPLAIN ANALYZE`), seguido de `File::count()` y los conteos por usuario de `media_edit_jobs`. Estos valores cambian lento, pero se recalculan en cada visita. Los monitores que sí son urgentes (RAM Disk, SHM, sesiones activas, jobs en background) son baratos y deben seguir refrescándose en vivo.

## What Changes

- Introduce un proveedor de datos del dashboard (`App\Services\Dashboard\DashboardDataProvider`) que arma el payload aplicando cache por tiers según volatilidad y costo.
- **Tier frío** (TTL ~15 min, `Cache::flexible` con stale-while-revalidate): `stats` globales (`total_users`, `total_storages`, `total_files`, `total_shares`, `active_shares`, `storage_used`) y agregados admin de `media_edit_jobs` (`clips_this_month`, `users_with_editor`, `near_limit`, `top_consumers`).
- **Tier tibio** (TTL ~2–5 min): resumen de cobertura de Mis Avisos, reutilizando el epoch de cobertura existente para invalidación inmediata.
- **Tier caliente** (sin cache): RAM Disk `/mnt/cliptemp`, SHM `/dev/shm`, sesiones activas, jobs en background y los conteos de enlaces del cliente.
- Añade metadata de frescura (`generated_at`, `stale`) al payload y un indicador visual "actualizado hace X" en el dashboard admin.
- Añade `dashboard:clear-cache` para invalidación manual operativa.
- **BREAKING** ninguno: el gating, la privacidad y el shape de los partials no cambian.

## Capabilities

### New Capabilities
- `dashboard-tiered-cache`: contrato de cache por tiers del payload de `/dashboard` (clasificación frío/tibio/caliente, TTLs, invalidación por epoch, anti-stampede y metadata de frescura).

### Modified Capabilities
- `dashboard-modular-partials`: los partials admin pueden recibir datos cacheados; se documenta el contrato de staleness y el indicador de frescura sin alterar gating ni privacidad.

## Non-goals

- No se rediseña la UI ni la estructura de los partials.
- No se cachean RAM Disk, SHM, sesiones activas ni jobs en background.
- No se introducen stores de cache nuevos (se usa Redis, el default).
- No se modifica `GET /bg-jobs/active` ni su polling.
- No se cambian queries de escritura ni el modelo de datos en v1.

## Impact

- `app/app/Http/Controllers/DashboardController.php` (delega en el provider).
- Nuevo `app/app/Services/Dashboard/DashboardDataProvider.php`.
- `app/app/Services/Ia/DashboardService.php` (TTL/epoch de cobertura).
- `app/resources/views/dashboard/admin.blade.php` (indicador de frescura).
- Nuevo comando `app/app/Console/Commands/ClearDashboardCacheCommand.php`.
- **Sin migración en v1** (todo en Redis). El `design.md` evaluará un contador materializado para `storage_used` si el TTL sigue produciendo picos al expirar.
