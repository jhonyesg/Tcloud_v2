## Why

Después de cerrar el ciclo completo del módulo de cobertura de watermarks (5 changes archivados), queda un hueco de UX importante: un admin nuevo entra al módulo y tiene que conocer varios endpoints (`/scan/audit`, `/scan/coverage`, `/scan/coverage/stats`) y correr comandos CLI (`avisos:check-partitioning-readiness`) para entender el estado del sistema. No hay una vista única que responda "¿está todo bien?" en menos de 5 segundos. Además, el audit log se ve en formato tabla plana — falta visualización temporal que muestre patrones de actividad, picos de errores, o anomalías operativas.

## What Changes

- **(1) Dashboard admin**: nueva pestaña "Dashboard" (la primera al entrar al módulo) con cards visuales mostrando:
  - Pares totales / pendientes / con hits
  - Drift negativo + huérfanos (estado)
  - Últimas 5 acciones del audit log
  - Estado de readiness de particionamiento (OK/WARNING/CRITICAL)
  - Corriendo / completado / error en scans recientes
- **(2) Timeline heatmap del audit log**: dentro de la pestaña Auditoría, agregar visualización calendar-style con la actividad de los últimos 90 días (calor de celda = # de acciones). Click en celda filtra el log por ese día. Sin librerías externas: SVG nativo o divs con color dinámico según el conteo.
- **(3) Endpoint dashboard**: `GET /avisos-inteligentes/scan/dashboard` (admin) que retorna JSON con todos los datos del dashboard en una sola respuesta (métricas, últimos logs, readiness).
- **(4) Endpoint heatmap**: `GET /avisos-inteligentes/scan/audit/heatmap?days=90` que retorna actividad agrupada por día para los últimos N días.
- **(5) UI integration**: nuevo card-link en el sidebar admin "Avisos · Dashboard" (o cambio del existente) que lleva a `?activeTab=dashboard`.

## Capabilities

### New Capabilities
- `avisos-scan-coverage-admin-dashboard`: endpoint + UI con métricas clave del módulo.
- `avisos-scan-coverage-audit-heatmap`: visualización temporal del audit log.

### Modified Capabilities
- (ninguno — sin cambio de comportamiento observable en APIs existentes)

## Impact

- **Archivos nuevos**:
  - `app/app/Services/Ia/DashboardService.php` (agregador)
  - `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` (2 métodos nuevos: dashboard, auditHeatmap)
  - `app/tests/harness_coverage_dashboard.php`
- **Archivos modificados**:
  - `app/routes/web.php` (2 rutas nuevas)
  - `app/resources/views/ia/avisos-inteligentes/index.blade.php` (nueva pestaña + sub-vista dashboard + heatmap)
  - `app/resources/views/layouts/app.blade.php` (link sidebar actualizado o añadido)
- **Tests nuevos**:
  - `tests/harness_coverage_dashboard.php`: verifica shape del dashboard endpoint, datos del heatmap, agregaciones correctas
- **Sin downtime, sin migraciones, sin breaking changes**.
- **Riesgo**: bajo. Toda la UI es aditiva; los endpoints existentes no cambian.
