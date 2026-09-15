## Why

Los dashboards admin y cliente (`/dashboard`) muestran solo información global de plataforma
(usuarios, storages, archivos, shares) y datos personales básicos (archivos, compartidos,
perfil, sesiones). Mientras tanto, módulos como **Editor de Medios** y **Mis Avisos** ya
calculan internamente métricas ricas (clips del mes, drift de cobertura, pares keyword×storage,
readiness de particionamiento) que solo son visibles si el operador entra a la vista
específica del módulo (`/ia/avisos-inteligentes`, `/admin/media-editor`).

Esto obliga al admin a abrir cada módulo para conocer el estado real del sistema y al
cliente a entrar a "Mis Avisos" para saber si su módulo está activo y cuánta cuota le queda.
El resultado: el dashboard principal no refleja la "salud" de los módulos inteligentes que
son los más críticos del producto.

Adicionalmente, agregar un módulo nuevo al dashboard hoy requiere editar `admin.blade.php` y
`user.blade.php` con bloques `@if` ad-hoc, lo cual ya muestra síntomas de deuda técnica.

## What Changes

- Introducir un **contrato de partials auto-gated** (`resources/views/dashboard/partials/`)
  donde cada módulo expone su card/panel como un partial Blade independiente que decide
  internamente si renderiza según el flag del modelo del usuario.
- Cablear el **primer lote** de partials (4): `_media-editor`, `_mis-avisos`,
  `_bg-jobs-active`, `_active-sessions`. Cada uno con su variante admin y/o cliente.
- `DashboardController::index()` deja de inyectar lógica por-módulo: solo carga los datos
  agregados necesarios y delega el render a los partials via `@include`.
- El admin gana un nuevo bloque "Módulos inteligentes" debajo de "Estadísticas de Uso".
- El cliente gana tarjetas modulares intercaladas en la grilla de acceso rápido existente.
- **No hay cambios en la ruta** `/dashboard` ni en el layout (`layouts/app.blade.php`).
- **No hay migración de BD**: toda la data ya existe en tablas actuales
  (`media_edit_jobs`, `keyword_scan_watermarks`, `watermark_audit_log`,
  `user_sessions`, `avisos_scan_runs`, `user_alerts_inteligentes`, etc.).
- **BREAKING**: `DashboardController` deja de inyectar `mediaEditorClipsUsed` /
  `mediaEditorClipLimit` / `mediaEditorEnabled` directamente al view `dashboard.user`
  (los encapsula el partial). Si algún caller externo lee esas variables, debe migrar al
  contrato del partial.

## Capabilities

### New Capabilities
- `dashboard-modular-partials`: Contrato genérico para partials auto-gated del dashboard,
  incluyendo el shape de datos, las reglas de gating y el orden de cableado de los
  partials del primer lote.

### Modified Capabilities
- (ninguna) — las specs existentes (`media-editor-access-control`,
  `avisos-scan-coverage-admin-dashboard`, etc.) siguen siendo válidas; este change no
  cambia sus requisitos, solo los hace visibles desde el dashboard principal.

## Impact

**Archivos afectados**:
- `app/app/Http/Controllers/DashboardController.php` — refactor a delega-via-include
- `app/resources/views/dashboard/admin.blade.php` — agregar bloque "Módulos inteligentes"
- `app/resources/views/dashboard/user.blade.php` — agregar tarjetas modulares en grilla
- `app/resources/views/dashboard/partials/_media-editor.blade.php` (nuevo)
- `app/resources/views/dashboard/partials/_mis-avisos.blade.php` (nuevo)
- `app/resources/views/dashboard/partials/_bg-jobs-active.blade.php` (nuevo)
- `app/resources/views/dashboard/partials/_active-sessions.blade.php` (nuevo)

**Servicios existentes reutilizados** (sin modificarlos):
- `App\Services\Ia\DashboardService` (admin Mis Avisos KPIs)
- `App\Services\Ia\WatermarkReconciler::driftReport()` (drift)
- `App\Models\User::mediaEditorClipsThisMonth()` / `canUseMediaEditor()` (editor)
- `App\Models\UserAlertsInteligente` (estado Mis Avisos cliente)
- `App\Services\BgJobRegistry` (jobs activos globales)

**No-goals** (explícito):
- No se rediseña el layout principal ni se cambia el sistema de tours interactivos.
- No se exponen métricas de auditoría (`watermark_audit_log`) al cliente.
- No se agrega la card `_personal-storage-quota` ni `_alert-activity` (queda para una
  segunda iteración una vez validado el patrón).
- No se hace drag-arrange de widgets ni se persiste preferencias por usuario.
- No se mueven datos entre vistas: `/ia/avisos-inteligentes` y `/admin/media-editor`
  siguen siendo las vistas profundas de cada módulo; el dashboard solo agrega resumen.
