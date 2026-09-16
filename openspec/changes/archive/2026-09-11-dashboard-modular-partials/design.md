## Context

Hoy `DashboardController::index()` (`app/app/Http/Controllers/DashboardController.php`)
ramifica por rol y construye **un solo array de variables** que se vuelca en
`admin.blade.php` o `user.blade.php`. Los módulos inteligentes (Editor de Medios,
Mis Avisos) tienen sus vistas admin/client profundas pero **no alimentan** al dashboard
principal.

Los servicios que ya calculan la data existen:

- `App\Services\Ia\DashboardService::build()` retorna KPIs agregados de Mis Avisos
  (`pairs_total`, `pairs_pending`, `pairs_with_hits`, `drift_negative`, `drift_orphan`,
  `audit_recent`, `scans_recent`, `readiness`) con cache TTL 10s.
- `App\Services\Ia\WatermarkReconciler::driftReport()` retorna el desglose de drift.
- `App\Models\User::mediaEditorClipsThisMonth()` / `canUseMediaEditor()` /
  `hasReachedClipLimit()` cubren el lado del editor.
- `App\Models\UserAlertsInteligente::enabled` / `keywords_quota` /
  `keywordsRemaining()` cubren el estado del módulo Mis Avisos del cliente.
- `App\Services\BgJobRegistry` ya alimenta el widget flotante
  (`bg-job-indicator.blade.php`) — solo hay que exponerlo a la vista admin.

La propuesta (ver `proposal.md`) fija el "qué"; este documento fija el "cómo".

## Goals / Non-Goals

**Goals:**
- Definir un contrato reusable para partials del dashboard con gating automático.
- Cablear el primer lote de 4 partials sin tocar lógica profunda de los módulos.
- Mantener compatibilidad con tours interactivos existentes (no romper selectores).

**Non-Goals:**
- No se introduce un sistema drag-arrange ni persistencia de preferencias por usuario.
- No se rediseñan `admin.blade.php` / `user.blade.php`; solo se agregan bloques.
- No se duplican vistas profundas: `/ia/avisos-inteligentes` y `/admin/media-editor`
  siguen siendo las vistas operativas de cada módulo.

## Decisions

### D1 — Patrón "partial self-gated" en `resources/views/dashboard/partials/`

**Decisión**: cada módulo expone un partial Blade que decide **internamente** si renderiza
según el flag del modelo (`$user->canUseMediaEditor()`, `$user->alertsInteligente?->enabled`,
`$data['users_with_editor'] > 0`, etc.). El `DashboardController` siempre hace
`@include('dashboard.partials._X', [...])` sin condicionar.

**Por qué**:
- Costo de agregar un módulo nuevo = **1 partial + 1 línea en el controller**.
- Cero ediciones a `admin.blade.php` / `user.blade.php` en iteraciones futuras.
- Mismo patrón que ya usa el proyecto para `bg-job-indicator.blade.php` (widget flotante
  global incluido desde `layouts/app.blade.php`).
- El partial es testeable unitariamente con `view('dashboard.partials._X', [...])`.

**Alternativas descartadas**:
- **A — inline en admin.blade.php/user.blade.php**: más rápido al inicio, pero cada módulo
  nuevo toca ambos archivos y los tours. Acumula deuda.
- **C — drag-arrange con tabla de prefs**: máxima flexibilidad pero requiere schema nuevo,
  JS, endpoints y migraciones. No se justifica para 4 widgets.

### D2 — Shape de datos tipado por contexto (`$context`)

**Decisión**: cada partial recibe `$context` (`'admin' | 'client'`) más `$user` y/o `$data`
según el caso. El shape de `$data` se documenta en el header del partial y se valida
visualmente con el primer cableado.

| Parcial | `$context` | Datos |
|---|---|---|
| `_media-editor` | ambos | `admin`: `{clips_this_month:int, users_with_editor:int, near_limit:int, top_consumers:array}` · `client`: `{clips_used:int, limit:int, enabled:bool}` |
| `_mis-avisos` | ambos | `admin`: subset de `DashboardService::build()` (`pairs_total`, `pairs_pending`, `pairs_with_hits`, `drift_negative`) · `client`: `{enabled:bool, keywords_used:int, keywords_quota:int, emails:array, emails_quota:int, cadence_minutes:int}` |
| `_bg-jobs-active` | solo admin | `{jobs:array<{kind, runId, module, label, startedAt, progress, url}>}` desde `BgJobRegistry` |
| `_active-sessions` | solo admin | `{total:int, top_users:array<{id, username, count}>}` |

**Por qué**:
- Tipos explícitos en el header del partial → no se reconsulta el modelo dentro del partial.
- El controller decide qué slice pasar según `$role`. El partial no tiene que saber.

**Alternativa descartada**: pasar siempre el `User` entero y leer desde el partial →
acopla el partial al modelo y complica el testing.

### D3 — Reutilización de `DashboardService` (sin modificarlo)

**Decisión**: el partial `_mis-avisos` consume el output de `DashboardService::build()` ya
cacheado (TTL 10s) **vía el controller**, no re-llamando al servicio internamente. El TTL
existente es aceptable para un resumen de dashboard.

**Por qué**: el servicio ya hace 4 queries (`keyword_scan_watermarks`,
`watermark_audit_log`, `avisos_scan_runs`, dos tablas para readiness) con cache. Reusarlo
es gratis.

**Riesgo aceptado**: si el admin edita watermarks desde otro panel, el dashboard puede
mostrar datos con hasta 10s de retraso. Aceptable.

### D4 — Gating client-side por flag del modelo

**Decisión**: el partial de cliente decide renderizar con `@if` consultando el flag
del modelo del usuario (pasado en `$user`). **No** consulta BD para auto-gatearse.

**Patrón concreto** (ejemplo Mis Avisos cliente):

```blade
@php
    $moduleEnabled = $user->alertsInteligente?->enabled
                  && ($user->alertsInteligente?->keywords_quota ?? 0) > 0;
@endphp
@if($moduleEnabled)
    {{-- render card --}}
@endif
```

**Por qué**: el flag ya está en el modelo (`UserAlertsInteligente::hasCupo()`), se usa
en otros lugares (`MisAvisosController::index` línea 26), y exponerlo vía `$user` evita
N+1.

### D5 — Tours interactivos: selectores estables

**Decisión**: cada partial envuelve su card en un `<div data-dashboard-partial="X">`
para que los tours futuros puedan enganchar selectores sin importar a la estructura
interna de Tailwind. **No** se modifican los tours existentes
(`startAdminDashboardTour` / `startUserDashboardTour`); los nuevos partials se documentan
como **tour-ready** pero no se cablean pasos automáticamente (queda para una iteración
posterior si el usuario lo pide).

### D6 — Estilo visual consistente

**Decisión**: cada partial usa el mismo shell visual que las cards existentes:
`bg-white rounded-xl shadow-sm border border-slate-200 p-5` con icono en cuadrado
`bg-<color>-100` (purple para editor, indigo/brand para Mis Avisos, slate para jobs,
green para sesiones).

**Por qué**: el dashboard actual ya usa este patrón; el cambio se ve como "más cards del
mismo estilo", no como un rediseño.

## Risks / Trade-offs

- **[R1] N+1 si no se acotan queries** → mitigación: el controller agrega en una sola
  query con `where user_id` y los conteos por status. Cada parcial recibe el slice ya
  calculado; nunca hace su propio `User::find` o `count`.
- **[R2] Cache 10s de DashboardService vs tiempo real** → aceptable para un resumen;
  el botón "Refrescar" del widget admin ya existe en `/ia/avisos-inteligentes` y puede
  replicarse después si el operador lo pide.
- **[R3] Breaking del contrato de variables del view `dashboard.user`** → las variables
  `mediaEditorEnabled` / `mediaEditorClipLimit` / `mediaEditorClipsUsed` se eliminan del
  payload del controller al migrar al partial. No hay callers externos (vistas Blade o JS)
  que las lean fuera de `user.blade.php`; se valida con grep antes de mergear.
- **[R4] Si un módulo nuevo olvida auto-gatearse** → se estresa con el patrón: el header
  del partial declara el flag esperado y la spec `dashboard-modular-partials/spec.md`
  fija el requisito `### Requirement: Cada partial expone un gating explícito`.
- **[R5] Cache fragmentado si dos partials piden lo mismo** → aceptable: las queries
  acotadas por `user_id` o `keyword_id` son baratas; la deduplicación es pedagógica, no
  de costo real.

## Migration Plan

1. **Deploy atómico del feature**:
   - Commit 1 (controller): refactor de `DashboardController` para inyectar `$data` por
     contexto, manteniendo compatibilidad con variables legacy (no se borran aún).
   - Commit 2 (partials): crear los 4 partials; cablearlos en `admin.blade.php` /
     `user.blade.php` con `@include`.
   - Commit 3 (cleanup): eliminar las variables legacy del controller y validar.

2. **Sin migración de BD** (cambio puramente de presentación).

3. **Rollback**: revert de los 3 commits + `php artisan view:clear`. No hay estado a
   purgar (no se persiste nada nuevo en BD).

4. **Feature flag opcional** (no se incluye en este change, queda como follow-up): un
   `DASHBOARD_MODULAR_PARTIALS=true|false` en `.env` que envuelva los `@include` para
   poder apagar el feature sin deploy.

## Open Questions

- ¿La card cliente de Mis Avisos debe ser **clickable** y navegar a `/ia/mis-avisos`?
  Recomendación: sí, wrapper `<a href="/ia/mis-avisos">`. Se confirma en
  implementación trivial.
- ¿Los partials admin deben incluir el botón "Reconciliar ahora" del `DashboardService`
  actual, o solo el número? Recomendación: solo el número en el resumen; el botón
  queda en la vista profunda `/ia/avisos-inteligentes` para evitar doble path de acción.
