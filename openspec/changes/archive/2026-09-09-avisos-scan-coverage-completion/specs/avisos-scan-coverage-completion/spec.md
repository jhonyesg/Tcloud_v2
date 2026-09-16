## Purpose

Completa el módulo de avisos inteligentes añadiendo la pieza client-facing (el cliente ve SU cobertura), el exportador XLSX, herramientas proactivas de readiness y métricas históricas, y la política de retención ajustable, dejando todas las utilidades operativas cubiertas.

## ADDED Requirements

### Requirement: Cada cliente ve su propia cobertura

El sistema SHALL ofrecer `GET /mis-avisos/coverage` (autenticado, sin requerir admin) que devuelve un JSON con la cobertura de las keywords asignadas al usuario autenticado, agrupada por storage, sin exponer pares de otros clientes. Cada fila incluye `keyword_id`, `keyword_text`, `storage_id`, `storage_name`, `scanned_until`, `last_hit_at`, `hits_total`. El endpoint SHALL aceptar query params `q` (LIKE en keyword_text) y `storageId` (filtro por storage). SHALL usar índices existentes de `keyword_scan_watermarks` y SHALL usar `Cache::remember` con TTL 30s + clave que incluye `user_id` (cache aislado por usuario).

#### Scenario: Cliente ve sus keywords con cobertura
- **WHEN** el cliente `user_id=5` autenticado hace `GET /mis-avisos/coverage`
- **THEN** recibe solo los pares `(keyword, storage)` de SUS keywords, no las de otros clientes

#### Scenario: Cliente filtra por texto
- **WHEN** el cliente escribe "alcaldía" en el input de búsqueda
- **THEN** recibe solo las keywords que coinciden con LIKE '%alcaldía%'

#### Scenario: Cliente sin permisos admin
- **WHEN** el cliente intenta `GET /admin/coverage`
- **THEN** recibe 403 (sin permisos admin), pero `/mis-avisos/coverage` funciona sin restricción adicional

### Requirement: Cliente puede solicitar rewind desde Mis Avisos

El sistema SHALL permitir que el cliente solicite un rewind para una keyword específica desde la UI de Mis Avisos. La solicitud SHALL pasar por `AvisosInteligentesController::rewindWatermark` (admin) o por un nuevo endpoint client-facing `POST /mis-avisos/rewind` que invoca el Reconciler directamente. SHALL tener throttling estricto (5/minuto) para evitar abuso. SHALL registrar en `watermark_audit_log` con `actor_user_id = usuario cliente` (no admin) y `metadata.requested_by = 'client_ui'`.

#### Scenario: Cliente hace rewind desde su UI
- **WHEN** el cliente hace POST /mis-avisos/rewind con keyword_id=X, storage_id=Y
- **THEN** el watermark se pone en NULL y el catch-up automático procesa las transcripciones del cliente; queda registro en audit log con actor_user_id=cliente

#### Scenario: Cliente excede throttling
- **WHEN** el cliente hace más de 5 rewinds por minuto
- **THEN** recibe 429 (rate limited) y no se ejecuta ninguna mutación

### Requirement: Exportador XLSX del audit log

El sistema SHALL ofrecer `GET /avisos-inteligentes/scan/audit/export-xlsx` (admin) con los mismos query params que el exportador CSV (`actor`, `action`, `keyword`, `storage`, `since`, `until`). SHALL generar un archivo `.xlsx` válido usando un generador XLSX nativo PHP (sin dependencias externas) o una librería nativa ya incluida. SHALL incluir headers correctos para Excel (UTF-8 BOM opcional, fechas serializadas como ISO8601). Cada fila SHALL tener las mismas columnas que el CSV (`timestamp`, `actor`, `action`, `keyword`, `storage`, `before`, `after`).

#### Scenario: Admin exporta audit log a XLSX
- **WHEN** el admin hace `GET /avisos-inteligentes/scan/audit/export-xlsx?action=rewind_pair`
- **THEN** recibe un archivo `audit-YYYY-MM-DD.xlsx` con todas las filas filtradas, formato Excel válido

#### Scenario: XLSX con caracteres especiales
- **WHEN** una fila contiene acentos o ñ (ej. "alcandía", "múes")
- **THEN** el archivo XLSX los renderiza correctamente al abrir en Excel

### Requirement: Comando de readiness de particionamiento

El sistema SHALL ofrecer `php artisan avisos:check-partitioning-readiness` que reporte el tamaño y conteo de filas de `watermark_audit_log` y `segment_keyword_hits` contra sus thresholds de partición (100k y 10M filas respectivamente). El comando SHALL imprimir una tabla con: nombre tabla, filas actuales, % sobre threshold, estado (OK/WARNING >70%/CRITICAL >100%), recomendación. Sin parámetros. Sin mutar nada.

#### Scenario: Volumen bajo threshold
- **WHEN** el admin ejecuta el comando y ambas tablas están por debajo del 70% de su threshold
- **THEN** recibe una tabla con estado OK para todas

#### Scenario: Una tabla cerca del threshold
- **WHEN** `watermark_audit_log` tiene 75,000 filas (75% de 100k)
- **THEN** recibe WARNING para esa tabla y recomendación de revisar el backlog 12.1

#### Scenario: Tabla supera el threshold
- **WHEN** `segment_keyword_hits` tiene 12M filas (>10M)
- **THEN** recibe CRITICAL y recomendación de ejecutar la migración de partición documentada en `archive/2026-09-09-avisos-keyword-storage-watermark/tasks.md` § 12.3

### Requirement: Política de retención del audit log configurable

El sistema SHALL permitir configurar la política de retención del audit log vía SystemSetting `audit_log_retention_days` (default 90). El comando `avisos:archive-audit-log` SHALL leer este setting cuando `--days` no se pasa explícitamente. Sin la flag ni el setting: usar 90 como default. Un endpoint admin `POST /avisos-inteligentes/scan/audit/retention` SHALL actualizar el setting (rango válido 30-3650 días, default 90).

#### Scenario: Admin configura retención a 180 días
- **WHEN** el admin hace `POST /avisos-inteligentes/scan/retention {"days": 180}`
- **THEN** la próxima corrida de `avisos:archive-audit-log` (sin `--days`) usa 180 como umbral

#### Scenario: Sin setting configurado
- **WHEN** `SystemSetting('audit_log_retention_days')` no existe
- **THEN** `archive-audit-log` usa 90 (default seguro)

#### Scenario: Valor fuera de rango
- **WHEN** el admin intenta configurar `days=1` o `days=10000`
- **THEN** recibe 422 con mensaje "Retention debe estar entre 30 y 3650 días"

### Requirement: Endpoint de métricas históricas de cobertura

El sistema SHALL ofrecer `GET /avisos-inteligentes/scan/coverage/stats` (admin) que devuelve series temporales de los últimos 30 días con: pares totales al final del día, pares pendientes (`scanned_until IS NULL`) al final del día, hits totales acumulados. Los datos SHALL calcularse agrupando por día sobre `created_at` de `keyword_scan_watermarks`. SHALL usar índice `created_at` para eficiencia (añadido si no existe). Respuesta JSON: `{ days: [{ date, total_pairs, pending_pairs, hits_total }] }`.

#### Scenario: Admin consulta evolución 30 días
- **WHEN** el admin hace `GET /avisos-inteligentes/scan/coverage/stats`
- **THEN** recibe 30 puntos (uno por día) con conteos al cierre del día

#### Scenario: Día sin eventos
- **WHEN** no hay filas con `created_at` en una fecha del rango
- **THEN** el día aparece con `total_pairs=0, pending_pairs=0, hits_total=0` (no se omite)

#### Scenario: Detectar regresión
- **WHEN** los pares pendientes suben 30% en 7 días
- **THEN** el admin detecta visualmente la regresión y puede investigar qué keyword o storage dejó de procesarse
