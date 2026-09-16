## 1. Provider y consolidación de queries (sin cache)

- [x] 1.1 Crear `app/app/Services/Dashboard/DashboardDataProvider.php` con `buildAdmin(User $user): array` y `buildClient(User $user): array`, moviendo la lógica de `buildAdminMediaEditorData`, `buildAdminMisAvisosData`, `buildAdminBgJobsData`, `buildAdminActiveSessionsData`, `buildClientMediaEditorData` y `buildClientMisAvisosData` desde `DashboardController`.
- [x] 1.2 Exponer cada bloque envuelto en el sobre `['data' => [...], 'generated_at' => ISO8601, 'stale' => bool]` sin cambiar el shape interno que documentan los headers de `resources/views/dashboard/partials/*.blade.php`.
- [x] 1.3 Consolidar los conteos fríos en `File::selectRaw('COUNT(*) AS c, COALESCE(SUM(size),0) AS s')->first()` (un solo scan en vez de `File::count()` + `File::sum('size')`).
- [x] 1.4 Reescribir `near_limit` y `top_consumers` del editor de medios para eliminar el N+1: una query agrupada de `media_edit_jobs` por `user_id` + join a `users`, comparando en PHP.
- [x] 1.5 Refactorizar `DashboardController::index()` para delegar en el provider, dejando la infraestructura (disk/SHM/instructivos/`personalStorageId`) en el controller y desembolsando `['data']` antes de pasar a la vista.
- [x] 1.6 Correr `php tests/harness_dashboard_partials.php` y `php tests/harness_mis_avisos_viewer.php`; ambos deben pasar sin cache activo (comportamiento idéntico al actual).

## 2. Cache por tiers (backend)

- [x] 2.1 Agregar constantes de TTL configurables por env (`DASHBOARD_COLD_TTL`/`DASHBOARD_COLD_STALE_TOTAL`, `DASHBOARD_WARM_TTL`/`DASHBOARD_WARM_STALE_TOTAL`) con defaults 900/1800 y 120/600.
- [x] 2.2 Cachear el bloque frío `dashboard:cold:stats` con `Cache::flexible([$fresh, $staleTotal], fn() => ...)`.
- [x] 2.3 Cachear el bloque frío `dashboard:cold:media-editor` con `Cache::flexible(...)`.
- [x] 2.4 Construir el resumen tibio de Mis Avisos con un método acotado (solo `pairs_total`, `pairs_pending`, `pairs_with_hits`, `drift_negative`) que NO pague `auditRecent`, `scansRecent`, `readiness` ni `driftReport` completo.
- [x] 2.5 Incluir `App\Services\Ia\CacheEpoch::get()` en la key tibia: `dashboard:warm:mis-avisos:e{epoch}`, con `Cache::flexible(...)`.
- [x] 2.6 Dejar el tier caliente (RAM/SHM, sesiones activas, bg_jobs) y el dashboard de cliente SIN cache, sin tocar `GET /bg-jobs/active`.
- [x] 2.7 Poblar `generated_at` y `stale` en el sobre leyendo la marca de creación de la entrada cacheada.

## 3. Frontend (Blade)

- [x] 3.1 En `app/resources/views/dashboard/admin.blade.php`, renderizar server-side el indicador de frescura ("actualizado hace X") para las tarjetas frías/tibias usando `generated_at`, sin introducir estado Alpine nuevo.
- [x] 3.2 Verificar que el gating y los `data-dashboard-partial` de `_media-editor`, `_mis-avisos`, `_bg-jobs-active` y `_active-sessions` no cambian.
- [x] 3.3 Confirmar que el dashboard cliente (`app/resources/views/dashboard/user.blade.php`) sigue recibiendo shapes idénticos sin sobre de cache.

## 4. Comando e invalidación manual

- [x] 4.1 Crear `app/app/Console/Commands/ClearDashboardCacheCommand.php` (`dashboard:clear-cache`) que haga `Cache::forget` de `dashboard:cold:*` y `dashboard:warm:*` sin tocar `coverage:dashboard` ni las keys del `WatermarkReconciler`.
- [x] 4.2 Registrar/verificar el comando y documentar el uso operativo en `AGENTS.md` (sección de operaciones del dashboard).

## 5. Validación y medición

- [x] 5.1 Medir con `EXPLAIN ANALYZE` el bloque frío antes/después y registrar el tiempo de carga de `/dashboard` en frío vs caliente.
- [x] 5.2 Verificar con logs que el segundo request no recalcula el bloque frío y que el recálculo tras expirar corre post-respuesta (deferred), no en línea.
- [x] 5.3 Verificar que un rewind/reconcile de watermarks se refleja en la tarjeta `_mis-avisos` en la siguiente carga (invalidación por epoch).
- [x] 5.4 Confirmar que el indicador de frescura muestra la antigüedad correcta y que el tier caliente (RAM/SHM/sesiones) cambia entre dos cargas consecutivas.
- [x] 5.5 Extender `tests/harness_dashboard_partials.php` o crear un harness nuevo que asegure que el sobre de cache no altera los shapes de los partials.

## 6. Rollback documentado

- [x] 6.1 Documentar en `AGENTS.md` el rollback (`git revert` del merge; sin migración; las claves Redis expiran solas) y el freno por env para subir TTL sin deploy.
