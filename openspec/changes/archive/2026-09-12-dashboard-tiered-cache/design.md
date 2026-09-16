## Context

`DashboardController::index()` (app/app/Http/Controllers/DashboardController.php:190) ejecuta hoy, en cada request y en línea, todos los agregados del dashboard. Medido con `EXPLAIN ANALYZE` sobre los datos reales: `files` tiene ~1.88M filas y `SELECT SUM(size)` tarda ~1.36 s; `File::count()` agrega otro seq/index scan; `buildAdminMediaEditorData()` hace N+1 conteos por usuario con editor. En contraste, los monitores operativos (`/mnt/cliptemp`, `/dev/shm`, `user_sessions`, `BgJobRegistry`) son baratos y sí deben cambiar en segundos.

Restricciones relevantes del proyecto:
- Cache driver Redis (`CACHE_DRIVER=redis`), Laravel 13.5. `Cache::flexible` (Repository.php:620) está disponible y usa locks de Redis, por lo que sirve para stale-while-revalidate sin infra nueva.
- Ya existe `App\Services\Ia\CacheEpoch` (`coverage_cache_epoch`) que incrementa en cada mutación de watermarks. La vista de Cobertura ya lo usa para invalidación inmediata.
- Convención: la caché persistente de negocio va por `SystemSetting`, pero la caché de consultas/HTML va por `Cache` (Redis) — es el caso aquí.
- `DashboardService::build()` ya cachea 10 s con key `coverage:dashboard` (sin epoch) y calcula más de lo que el dashboard consume (auditRecent, scansRecent, readiness, driftReport).

Ver proposal.md para la motivación.

## Goals / Non-Goals

**Goals:**
- Bajar el tiempo de carga inicial del dashboard admin evitando recalcular en cada request los agregados sobre `files` y `media_edit_jobs`.
- Mantener frescos (por request) los monitores de alerta: RAM Disk, SHM, sesiones activas, jobs en background.
- No romper gating, privacidad ni el shape de los partials existentes.
- Invalidación inmediata del bloque de cobertura ante mutaciones de watermarks.
- Poder invalidar manualmente sin deploy.

**Non-Goals:**
- Rediseñar la UI del dashboard o los partials.
- Introducir tabla/contador materializado para `storage_used` en v1 (se evalúa como Open Question).
- Cachear el dashboard de cliente cuyos valores dependen del usuario en v1.
- Cambiar `GET /bg-jobs/active` ni su polling.

## Decisions

### D1 — Extraer un `DashboardDataProvider` que separe armar el payload de renderizar

Se crea `App\Services\Dashboard\DashboardDataProvider` con `buildAdmin(User $user): array` y `buildClient(User $user): array`. `DashboardController::index()` queda como orquestador: arma stats de infraestructura (disk/SHM), llama al provider y pasa `$dashboardData` a la vista. Alternativa considerada: meter el cache inline en cada método privado del controller. Se descarta porque dispersa la política de TTL y hace el tiering difícil de testear.

Cada bloque se expone envuelto en un sobre uniforme:
```php
['data' => [...shape actual...], 'generated_at' => ISO8601, 'stale' => bool]
```
El controller extrae `['data']` para los partials, preservando exactamente el shape documentado en los headers de `resources/views/dashboard/partials/*.blade.php`.

### D2 — Tiers, keys y TTLs

| Tier | Bloque | Key | Fresh | Stale total |
|---|---|---|---|---|
| Frío | stats globales + `storage_used` | `dashboard:cold:stats` | 900 s | 1800 s |
| Frío | agregados admin media editor | `dashboard:cold:media-editor` | 900 s | 1800 s |
| Tibio | resumen Mis Avisos | `dashboard:warm:mis-avisos:e{epoch}` | 120 s | 600 s |
| Caliente | RAM/SHM, sesiones, bg_jobs | sin cache | — | — |

Se usa `Cache::flexible($key, [$fresh, $staleTotal], $callback)`: sirve el valor viejo y **recalcula después de enviar la respuesta** (usa `defer()`), eliminando el pico de latencia al expirar y el cache stampede. El lock de Redis garantiza un único recálculo concurrente.

Se descarta el TTL único de 10–15 min propuesto inicialmente porque congelaría los monitores operativos justo cuando importan (gauges >90%).

### D3 — Invalidación del tier tibio por epoch existente

La key del resumen de Mis Avisos incluye `CacheEpoch::get()`. Como `CacheEpoch::bump()` corre en cada mutación del `WatermarkReconciler`, la siguiente carga del dashboard ve el estado post-mutación sin esperar los 120 s. Nota: el cache interno de `DashboardService::build()` (`coverage:dashboard`, 10 s) NO está atado al epoch; el provider debe consumir una ruta que incluya el epoch o llamar al servicio con invalidación por epoch. Se prefiere construir el resumen del dashboard con un método acotado del servicio (solo `pairs_*` + `drift_negative`) para no pagar `auditRecent`/`scansRecent`/`readiness`/`driftReport` completo en una ruta que no los usa.

### D4 — Metadata de frescura

El sobre incluye `generated_at` (leído de `illuminate:cache:flexible:created:{key}` o del propio payload) y `stale` (`created + fresh < now`). En `app/resources/views/dashboard/admin.blade.php`, en el header del bloque "Módulos Inteligentes" o de cada tarjeta cacheada, se renderiza server-side un texto tipo `Datos actualizados hace X min` con `@php`/Carbon. No se introduce estado Alpine nuevo: es texto estático por carga; el selector de tour `data-dashboard-partial` permanece intacto. Se usa `session('user_id')`/`session('user_role')` como en el resto del controller — nunca `auth()->user()`.

### D5 — Consolidar queries del tier frío y quitar N+1

- `File::count()` + `File::sum('size')` → un único `File::selectRaw('COUNT(*) AS c, COALESCE(SUM(size),0) AS s')->first()` (un solo scan en vez de dos).
- `near_limit` con N+1 → una sola query agrupada `media_edit_jobs` por `user_id` + join a users con `media_editor_clip_limit > 0`, comparando en PHP.
- El resto de stats (users, storages, shares) sigue igual; son baratos y pueden vivir dentro del mismo bloque frío.

### D6 — Comando de invalidación manual

`php artisan dashboard:clear-cache` ejecuta `Cache::forget` sobre las keys de tier frío y tibio (no toca `coverage:dashboard` ni las keys del reconciler). Registrado en `app/app/Console/Commands/ClearDashboardCacheCommand.php`.

### D7 — Cliente sin cache en v1

`buildClient*` usa conteos por-usuario con índices (`idx_media_edit_jobs_user_status_date`, `user_keyword_user_id_keyword_id_unique`) y una config de 1 fila; el costo es bajo y el riesgo de filtrar datos entre usuarios al cachear por-request es real. Se deja en vivo. Si a futuro se cachea, la key DEBE incluir `user_id`.

## Risks / Trade-offs

- **[`SUM(size)` sigue costando ~1.3 s cada 15 min]** → mitigado por `Cache::flexible` (recálculo post-respuesta, no bloquea al usuario). Si el `defer` no bastara en producción, la salida es un contador materializado actualizado por `storage:sync` (Open Question).
- **[Datos fríos con hasta 15 min de desfase]** → aceptado y visible: el operador ve `actualizado hace X`. Los KPIs fríos no son accionables en segundos.
- **[`segment_keyword_hits` crece y `driftReport` es O(pares²) en memoria]** → el provider consume una ruta acotada (D3); `driftReport` queda fuera del camino caliente. A futuro, mover el drift a SQL.
- **[Cache tibio mostrando estado viejo tras mutación]** → cubierto por epoch (D3) + comando manual (D6).
- **[Stale-while-revalidate depende de que `defer()` corra tras la respuesta]** → en Laravel FPM estándar sí (terminate); el harness debe verificar que el segundo request no recalcule en línea.
- **[Tests de partials existentes]** → `tests/harness_dashboard_partials.php` asume shapes; el sobre `['data'=>...]` se desenvuelve antes de pasar a los partials para no romperlo.

## Migration Plan

1. Mergear provider + controller refactor detrás del comportamiento actual (sin cache todavía) y correr `tests/harness_dashboard_partials.php` + `tests/harness_mis_avisos_viewer.php`.
2. Activar tier frío con TTLs y `Cache::flexible`; medir con `EXPLAIN ANALYZE` y logs de tiempo.
3. Activar tier tibio con epoch; verificar que un rewind se refleja en la siguiente carga.
4. Agregar indicador de frescura y comando `dashboard:clear-cache`.

**Rollback**: `git revert` del merge. Sin migración en v1; no hay estado persistente que limpiar (claves Redis expiran solas). Freno alternativo sin deploy: subir TTL no requiere código si se parametriza por env (`DASHBOARD_COLD_TTL`).

## Open Questions

- ¿Un contador materializado de `storage_used` (actualizado por `storage:sync` cada 15 min) vale la pena frente a `Cache::flexible`? Se decide tras medir el impacto real del deferred refresh en producción.
- ¿Conviene un endpoint AJAX para refrescar solo las tarjetas frías al hacer click ("actualizar ahora"), o basta con recargar la página? No cambia las specs ni la arquitectura; se puede añadir después.
