# avisos-scan-coverage-admin-dashboard Specification

## Purpose
Proporciona al administrador una vista única de salud del módulo de cobertura de watermarks (métricas clave + readiness de particionamiento + últimos eventos del audit log) y una visualización temporal del audit log que facilita detectar patrones y anomalías operativas.

## Requirements

### Requirement: Endpoint dashboard con métricas clave

El sistema SHALL ofrecer `GET /avisos-inteligentes/scan/dashboard` (admin) que retorna un JSON con: `pairs_total`, `pairs_pending` (scanned_until NULL), `pairs_with_hits` (hits_total > 0), `drift_negative` (pares aplicables faltantes), `drift_orphan` (pares sin usuarios aplicables), `audit_recent` (últimas 5 acciones), `scans_recent` (últimas 3 corridas), `readiness` (objeto con watermark_audit_log y segment_keyword_hits cada uno con `count`, `threshold`, `pct`, `status`), `generated_at` (timestamp). El endpoint SHALL ejecutar las queries de agregación en paralelo cuando sea posible (cache 10s) y SHALL responder en < 500 ms con datasets actuales.

#### Scenario: Admin abre el módulo
- **WHEN** el admin hace `GET /scan/dashboard`
- **THEN** recibe un JSON con todos los contadores en menos de 500 ms

#### Scenario: Drift negativo detectado
- **WHEN** hay pares aplicables sin watermark (drift > 0)
- **THEN** el dashboard muestra `drift_negative > 0` y sugiere "Ejecutar avisos:reconcile-watermarks"

#### Scenario: Readiness cerca del threshold
- **WHEN** `watermark_audit_log` tiene > 70% del threshold
- **THEN** el dashboard muestra `readiness.watermark_audit_log.status = "WARNING"` con conteo exacto

### Requirement: Heatmap del audit log últimos N días

El sistema SHALL ofrecer `GET /avisos-inteligentes/scan/audit/heatmap?days=90` (admin) que retorna un array de `{ date, total_actions, by_action: { rewind_pair: N, full_scan: N, hook_auto: N, reconcile: N } }` por cada día en el rango. SHALL usar índice `wal_action_time_idx` o escaneo de tabla agrupado por DATE(created_at). SHALL responder en < 1s para 100k filas.

#### Scenario: Admin consulta heatmap
- **WHEN** el admin hace `GET /scan/audit/heatmap?days=90`
- **THEN** recibe 90 puntos con el conteo total de acciones por día

#### Scenario: Día con múltiples tipos de acción
- **WHEN** un día tiene 3 rewinds, 15 hook_autos y 1 reconcile
- **THEN** el JSON incluye `total_actions: 19, by_action: { rewind_pair: 3, hook_auto: 15, reconcile: 1 }`

#### Scenario: Día sin actividad
- **WHEN** no hay entradas en `watermark_audit_log` para una fecha
- **THEN** el día aparece con `total_actions: 0, by_action: {}` (no se omite)

### Requirement: UI dashboard con cards de métricas

La nueva pestaña "Dashboard" SHALL mostrar:
- 4 cards superiores: Pares totales, Pendientes catch-up, Con hits (acumulado), Drift negativo
- 1 card central: Readiness de particionamiento con barra de progreso visual y estado (OK/WARNING/CRITICAL) por tabla
- 2 cards laterales: Últimas 5 acciones del audit log, Últimas 3 corridas de scan
- Carga al entrar a la pestaña (no background); refresh manual con botón.

#### Scenario: Admin abre Dashboard
- **WHEN** el admin hace click en la pestaña Dashboard
- **THEN** ve las 4 cards superiores con números grandes, la card de readiness con barras de progreso, y las listas de últimos eventos

#### Scenario: Click en "Ejecutar reconcile" desde el dashboard
- **WHEN** el dashboard muestra drift_negative > 0 y el admin hace click en el botón "Reconciliar ahora"
- **THEN** ejecuta POST /scan/reconcile (dry_run=false) y refresca el dashboard

### Requirement: Heatmap visual con calendar grid en la UI

La pestaña Auditoría SHALL incluir, sobre la tabla de logs, un calendar grid de 90 días (últimos 3 meses) con:
- Cada celda = 1 día, color de fondo según `total_actions` (escala de 5 colores: vacío → muy cargado)
- Click en celda filtra el log por ese día (auto-llena el campo `since`/`until`)
- Tooltip al hover: "2026-09-09: 19 acciones (3 rewinds, 15 hooks, 1 reconcile)"
- Leyenda con escala de colores debajo

#### Scenario: Admin visualiza heatmap
- **WHEN** el admin abre la pestaña Auditoría
- **THEN** ve 90 celdas (grid 13×7) con colores representando la actividad diaria

#### Scenario: Click en una celda
- **WHEN** el admin hace click en la celda del 2026-09-09
- **THEN** el filtro `since` se pone a `2026-09-09` y `until` a `2026-09-09`, y la tabla de log se recarga mostrando solo ese día

#### Scenario: Día sin actividad
- **WHEN** no hubo acciones en una fecha del rango
- **THEN** la celda aparece en color "vacío" (gris claro)

### Requirement: Link sidebar al dashboard

El sidebar admin SHALL tener un enlace que lleva directamente al dashboard (`?activeTab=dashboard`). El label SHALL ser "Avisos · Dashboard" o mantener el actual "Avisos Inteligentes" con el flag activeTab=dashboard como query param. La pestaña Dashboard SHALL ser la pestaña por defecto al entrar al módulo (no Cobertura).

#### Scenario: Admin hace click en sidebar
- **WHEN** el admin hace click en el enlace del sidebar
- **THEN** aterriza en la pestaña Dashboard (la primera que ve)
