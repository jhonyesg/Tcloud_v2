# avisos-scan-coverage-audit-ui Specification

## Purpose
Permite al administrador consultar, buscar y exportar el historial de acciones administrativas y automáticas sobre la cobertura de watermarks desde la UI, sin necesidad de escribir SQL, y mantener ese historial acotado en tamaño mediante una política de archivado configurable.

## Requirements

### Requirement: Panel de auditoría consultable desde la UI

El sistema SHALL ofrecer una sub-pestaña "Auditoría" dentro de la pestaña Cobertura que muestra una tabla paginada (25/50/100) del `watermark_audit_log` con las columnas: `created_at`, `actor` (username o "sistema"), `action` (rewind_pair / full_scan / hook_auto / reconcile), `keyword` (texto), `storage` (nombre), `before_value`, `after_value`. La tabla SHALL tener filtros por: actor (LIKE), action (select con los 4 valores), keyword (LIKE), storage (LIKE), rango de fechas (since/until). El panel SHALL incluir un botón "Exportar CSV" que descarga el resultado filtrado (no limitado a la página visible) — formato CSV con las mismas columnas.

#### Scenario: Admin abre la pestaña Auditoría
- **WHEN** el admin hace clic en la sub-pestaña "Auditoría" dentro de Cobertura
- **THEN** ve una tabla paginada con las últimas 25 entradas del audit log, ordenadas por `created_at` DESC, con los filtros visibles arriba

#### Scenario: Admin filtra por actor
- **WHEN** el admin escribe "jsuarez" en el filtro de actor
- **THEN** la tabla muestra solo las filas con `actor_user_id` que corresponde a ese username, con el conteo total actualizado

#### Scenario: Admin filtra por rango de fechas
- **WHEN** el admin selecciona `since=2026-09-01` y `until=2026-09-15`
- **THEN** la tabla muestra solo las filas con `created_at` dentro de ese rango

#### Scenario: Admin exporta CSV filtrado
- **WHEN** el admin aplica filtros y hace clic en "Exportar CSV"
- **THEN** se descarga un archivo `audit-YYYY-MM-DD.csv` con TODAS las filas que cumplen los filtros (no limitado a la página actual), con las columnas: timestamp, actor, action, keyword_text, storage_name, before, after

### Requirement: Endpoint de consulta del audit log con paginación y filtros

El sistema SHALL ofrecer `GET /avisos-inteligentes/scan/audit` (admin only) con query params `page`, `per_page` (default 25, max 100), `actor` (LIKE), `action` (enum), `keyword` (LIKE), `storage` (LIKE), `since` (Y-m-d), `until` (Y-m-d). SHALL devolver JSON con `items`, `total`, `current_page`, `last_page`, `per_page`. Cada `item` SHALL incluir JOIN con `keywords.text` y `storage_providers.name` para evitar N+1 en el cliente. SHALL usar índices `wal_actor_time_idx` o `wal_action_time_idx` o `wal_ks_time_idx` (verificable con EXPLAIN).

#### Scenario: Query típica usa índice
- **WHEN** se ejecuta `GET /scan/audit?action=rewind_pair&page=1`
- **THEN** el plan usa `wal_action_time_idx` (verificado con EXPLAIN), tiempo < 50 ms sobre 100k filas

#### Scenario: Filtro compuesto
- **WHEN** se ejecuta `GET /scan/audit?actor=jsuarez&action=rewind_pair&since=2026-09-01`
- **THEN** la respuesta contiene solo filas que cumplen los 3 filtros simultáneamente

### Requirement: Política de archivado configurable del audit log

El sistema SHALL ofrecer `php artisan avisos:archive-audit-log --days=N` (default 90) que mueva las filas con `created_at < now() - INTERVAL 'N days'` desde `watermark_audit_log` hacia `watermark_audit_log_archive` (tabla idéntica + columna `archived_at`). La operación SHALL ser transaccional por chunks de 1000 filas para no bloquear escrituras concurrentes. Con `--dry-run`, SHALL solo contar las filas candidatas sin moverlas. Tras la operación SHALL imprimir cuántas filas se archivaron y el rango de fechas cubierto. El comando SHALL estar disponible sin agendarse automáticamente — el operador lo corre mensualmente o lo agenda vía cron.

#### Scenario: Archivado dry-run
- **WHEN** el admin ejecuta `php artisan avisos:archive-audit-log --days=90 --dry-run`
- **THEN** imprime "N filas candidatas (created_at < X)" sin modificar el estado

#### Scenario: Archivado mueve filas en chunks
- **WHEN** el admin ejecuta `php artisan avisos:archive-audit-log --days=90` y hay 5000 filas candidatas
- **THEN** se ejecutan 5 transacciones de 1000 filas cada una; el log activo queda con las filas de los últimos 90 días; las filas más viejas quedan en `watermark_audit_log_archive` con `archived_at = now()`

#### Scenario: Archivado idempotente
- **WHEN** el comando se ejecuta dos veces seguidas sin nuevas filas
- **THEN** la segunda ejecución mueve 0 filas (las candidatas de la primera ya están en el archive) y termina con exit 0

### Requirement: Tabla archive con índices para auditoría histórica

El sistema SHALL persistir filas antiguas en `watermark_audit_log_archive` con la misma estructura que `watermark_audit_log` más la columna `archived_at`. SHALL tener índices `(actor_user_id, created_at)`, `(action, created_at)` y `(keyword_id, storage_id, created_at)` para responder a las mismas preguntas que el log activo pero sobre histórico.

#### Scenario: Consulta sobre filas archivadas
- **WHEN** un auditor (admin) ejecuta `SELECT * FROM watermark_audit_log_archive WHERE actor_user_id=5 AND created_at < '2026-06-01'`
- **THEN** el plan usa `wal_archive_actor_time_idx` y retorna las filas que el admin archivó en su día
