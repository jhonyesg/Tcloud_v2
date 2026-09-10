# Proposal: Mis Avisos — Filtrar por fecha del programa, no de detección

## Why

Hoy el cliente del módulo **Mis Avisos** busca "WinSport del 7 de septiembre tipo 2:15 pm" en el **Histórico** y obtiene **0 coincidencias**, aunque el sistema tiene 9 menciones de la keyword "Omar Perez" detectadas en ese programa. La raíz es que el filtro del histórico compara `segment_keyword_hits.matched_at` (cuándo el motor de matching procesó la mención, p. ej. `2026-09-09 12:18:18 UTC`) contra el rango pedido por el usuario (`2026-09-07`). Son conceptos distintos y el sistema los confunde.

El modelo mental del cliente es **fecha del programa** (cuándo se emitió el contenido). El modelo del sistema actual es **fecha de detección** (cuándo el cron/proceso encontró la keyword). El bug se reproduce cada vez que un cron atrasa o cuando una transcripción histórica se procesa en batch (caso real verificado 2026-09-10: transcripción del 7 sep a las 14:30, hits detectados el 9 sep a las 17:18 hora local → 2 días de desfase).

Esta incongruencia es **estructural**, no un parche de una línea: el sistema no distingue ambos conceptos en el modelo de datos ni en la UI. Una corrección puntual (cambiar `matched_at` por `t.finished_at` en una sola query) deja el problema latente para:
- Próximas consultas con desfase de detección.
- Próximos filtros (export CSV, digests, futuras vistas).
- Migración a particionamiento por mes (en curso: `2026_09_15_120100_prepare_segment_keyword_hits_partitioning.php`), que se particiona por `matched_at` — si los hits viejos tienen `matched_at` reciente y `recorded_at` antiguo, las particiones mensuales van a quedar asimétricas.

## What Changes

### Modelo de datos
- **Nueva columna** `transcriptions.recorded_at TIMESTAMPTZ NULL` que representa la **fecha real del programa** (cuándo se emitió el audio). Distinta de `finished_at` (cuándo el transcriptor terminó) y de `matched_at` (cuándo se hizo el match).
- **Backfill** de la columna para todas las filas existentes, derivando del nombre del archivo (formato `*_DDMMYYYY_HHMMSS.*`), con fallback a `file_modified_at`, con fallback a `finished_at`.
- **Nuevos setters** en los puntos donde se crea una transcripción (`TranscriptionService`, `ScanAndSubmitCommand`, paths de upload manual) para calcular `recorded_at` automáticamente al insertar.

### Capa de servicio
- **`MentionsSearchService`** acepta un nuevo parámetro `date_field` (`'program' | 'detected'`, default `'program'`).
  - `program` filtra por `t.recorded_at` (lo que el cliente espera).
  - `detected` filtra por `h.matched_at` (comportamiento actual, preservado para casos como "qué encontré esta semana" o métricas internas).
- **`searchHistory`, `todayHits`, `applyHitFilters`** propagan `date_field` al query builder.
- **`hitRow`** agrega `recorded_at` a la respuesta para que la UI lo muestre.

### Controller
- **`MisAvisosController::history`** y **`feed`** aceptan `date_field` en el request (whitelist + default).
- **`preferences`** (proyección últimos 7 días) usa **`detected`** explícito — la pregunta "¿cuántas menciones voy a recibir?" es de detecciones, no de programas.
- **`mentionsExportJob`** propaga `date_field` del request al job.
- **`AlertDeliveryService` y `SendAlertDigest`** NO cambian: las alertas se entregan sobre detecciones, no sobre programas. Documentado explícitamente.

### UI
- **Toggle en el histórico de Mis Avisos**: "Filtrar por: **Fecha del programa** (recomendado) / **Fecha de detección**". Default: programa. Alineado a la derecha del filtro de fechas.
- **Columna nueva en la tabla de hits**: "Programa" muestra `t.recorded_at` con formato `YYYY-MM-DD HH:mm`. La columna "Detectado" existente sigue mostrando `matched_at`. Operador ve ambos sin ambigüedad.
- **Tooltip** en el toggle explicando la diferencia con un ejemplo concreto (WinSport del 7 sep a las 14:30 detectado el 9 sep).
- **`dateShortcuts`** (Hoy / Ayer / 3 días / 7 días) se interpretan contra el campo activo del toggle — sin cambio de comportamiento, pero la semántica se aclara.

### Spec
- **Nueva capacidad `mis-avisos-program-date`** en `openspec/specs/` con sus requirements y escenarios (cuándo aplicar default, cuándo el cliente debe poder cambiarlo, qué campos de UI muestran qué fecha).

## Impact

### Capacidades afectadas
- `mis-avisos-admin-preview` — comportamiento cambia (default).
- `mentions-historical-export` — query cambia por default; export respeta toggle.
- `mentions-viewer` — el visor de transcripción individual sigue usando el archivo/audio, sin cambio.
- `universal-matching-engine` — el motor sigue produciendo `matched_at` en hits; no cambia la captura.
- `avisos-keyword-storage-watermark` — el cursor `scanned_until` sigue siendo `finished_at`; sin cambio. Se documenta explícitamente que `recorded_at` es para UI, no para lógica de cursor.

### Comportamiento por defecto
- **Hoy**: cliente busca "7 sep" → 0 resultados. Insatisfactorio.
- **Después del cambio (default `program`)**: cliente busca "7 sep" → ve las menciones que SÍ corresponden al programa del 7 sep. Intuitivo.

### Riesgo de regresión
- Usuarios que **sí querían fecha de detección** tienen el toggle disponible. Default favorece la intuición. Si una métrica interna dependía de `matched_at` para queries ad-hoc en BD, no se ve afectada (la columna sigue existiendo).
- Backfill de `recorded_at` puede dejar NULL en archivos cuyo nombre no siga el patrón estándar. La query debe tolerarlo: si `recorded_at IS NULL`, comportamiento de fallback al `finished_at`. Documentado en spec.

### Fuera de alcance
- Reescribir `AvisosInteligentesController` o `MentionBackfillService` (usan `matched_at` solo para ordenamiento, no para filtrar).
- Cambiar el cursor `scanned_until` del watermark (sigue siendo `finished_at` por diseño — representa "hasta dónde llegó el escaneo", no "fecha del programa").
- Migrar la partición mensual de `segment_keyword_hits` a `recorded_at` (cambio mayor de particionamiento — se discute en el change `partition-segment-keyword-hits` cuando se supere el trigger de 10M filas).

## Success Criteria

1. Cliente Monitoreoalpunto busca "Omar Perez" el 7 sep 2026 y obtiene las 9 menciones que el sistema tiene registradas (verificado vía Playwright con sesión autenticada del cliente).
2. Admin dispara `transcriptions.recorded_at` para todos los archivos existentes y el 100% tiene valor no nulo.
3. Toggle "Fecha del programa / Fecha de detección" en Mis Avisos funciona; ambos modos devuelven conjuntos coherentes (no vacíos cuando hay datos).
4. Export CSV refleja el toggle elegido (mismas filas que en pantalla).
5. Métricas internas (proyección de preferencias, alertas del día, digests) siguen funcionando y muestran valores coherentes con el campo `matched_at`.
6. No regresión en suite de tests existente (`harness_mis_avisos_menciones`, `harness_mis_avisos_viewer`).
