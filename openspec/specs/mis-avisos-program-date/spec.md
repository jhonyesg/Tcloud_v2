# Spec: mis-avisos-program-date

## Purpose

Permite al módulo Mis Avisos filtrar el histórico y el feed por la fecha del programa (cuándo se emitió el audio), no por la fecha de detección (cuándo el cron procesó el match). Resuelve el bug donde el cliente buscaba un programa del 7 de septiembre y obtenía 0 hits porque el sistema filtraba por la fecha de detección (que podía ser días después).

## Requirements

### Requirement: `transcriptions.recorded_at` representa la fecha del programa

El sistema SHALL persistir en `transcriptions.recorded_at` (TIMESTAMPTZ, nullable) la fecha y hora en que el audio fue emitido originalmente. La columna SHALL ser inmutable después de la creación de la fila.

#### Scenario: Transcripción creada con naming estándar
- **WHEN** se crea una transcripción a partir de un archivo cuyo nombre sigue el patrón `*_DDMMYYYY_HHMMSS.{mp4,mp3,m4a,...}` (ej. `winsport_07092026_141502.mp4`)
- **THEN** `recorded_at` se setea a `2026-09-07 14:15:02` en zona horaria local del servidor

#### Scenario: Archivo sin naming estándar usa file_modified_at
- **WHEN** el nombre del archivo no contiene fecha parseable pero `files.file_modified_at` está poblado
- **THEN** `recorded_at` se setea a `file_modified_at` convertido a TIMESTAMPTZ

#### Scenario: Sin fuentes de fecha, queda NULL
- **WHEN** el nombre del archivo no es parseable Y `file_modified_at` es NULL
- **THEN** `recorded_at` queda NULL y la fila es visible para auditoría

#### Scenario: Backfill cubre el histórico
- **WHEN** se aplica la migración inicial del campo
- **THEN** ≥99% de las filas existentes tienen `recorded_at` no nulo (verificado vía reporte de la migración)

#### Scenario: Inmutabilidad post-creación
- **WHEN** se intenta `UPDATE transcriptions SET recorded_at = ... WHERE id = X` para una fila existente
- **THEN** el cambio es rechazado (silent no-op o excepción según decisión del boot observer)

### Requirement: Mis Avisos filtra por fecha del programa por default

El sistema SHALL filtrar las consultas del módulo Mis Avisos (Histórico y En vivo) por `transcriptions.recorded_at` por default. El cliente SHALL poder cambiar el filtro a `segment_keyword_hits.matched_at` (fecha de detección) mediante un toggle en la UI.

#### Scenario: Cliente busca por fecha del programa
- **WHEN** el cliente abre Mis Avisos → Histórico y deja el toggle en "Fecha del programa"
- **AND** filtra por rango `2026-09-07 a 2026-09-07`
- **AND** tiene asignada la keyword "Omar Perez"
- **AND** existen hits de "Omar Perez" en archivos cuyo `recorded_at` cae en ese rango
- **THEN** el cliente ve esos hits en la tabla

#### Scenario: Caso Monitoreoalpunto reproduce el bug fix
- **GIVEN** un cliente con keyword "Omar Perez" asignada
- **AND** acceso al storage "24 WinSport"
- **AND** existen 9 hits con `transcriptions.recorded_at = 2026-09-07 14:00 a 14:45`
- **AND** esos mismos hits tienen `segment_keyword_hits.matched_at = 2026-09-09 17:18` (detectados 2 días después)
- **WHEN** el cliente busca Mis Avisos → Histórico → fecha `2026-09-07 a 2026-09-07`, keyword "Omar Perez"
- **THEN** ve 9 resultados (no 0)

#### Scenario: Toggle a fecha de detección cambia el resultado
- **WHEN** el cliente activa el toggle "Fecha de detección"
- **AND** filtra por el mismo rango `2026-09-07 a 2026-09-07`
- **THEN** ve 0 resultados (porque `matched_at = 2026-09-09`, fuera del rango)

### Requirement: Métricas internas usan fecha de detección explícitamente

El sistema SHALL usar `segment_keyword_hits.matched_at` (no `transcriptions.recorded_at`) en los siguientes contextos, pasando `date_field=detected` explícito al servicio:

- Proyección de "últimos 7 días" en Preferencias (`MisAvisosController::preferences`).
- Generación del digest de alertas (`SendAlertDigest`).
- Selección de hits para envío de alertas (`AlertDeliveryService::selectHits`).
- Exportación CSV cuando el cliente eligió explícitamente fecha de detección.

#### Scenario: Proyección de preferencias cuenta detecciones
- **WHEN** se llama `preferences()` para un cliente
- **THEN** `hits_last_7_days` cuenta hits con `matched_at >= now - 7 days` (no con `recorded_at`)

### Requirement: UI expone ambos modos sin ambigüedad

La tabla de hits del módulo Mis Avisos SHALL mostrar **dos columnas de fecha**: "Programa" (`recorded_at`, principal) y "Detectado" (`matched_at`, secundaria, con estilo visual atenuado). El toggle SHALL estar visible en el formulario de filtros del Histórico.

#### Scenario: Toggle visible en Histórico
- **WHEN** el cliente abre Mis Avisos → Histórico
- **THEN** ve el toggle "Filtrar por: [● Fecha del programa] [○ Fecha de detección]" cerca de los inputs Desde/Hasta
- **AND** por default el toggle está en "Fecha del programa"
- **AND** hay un tooltip que explica la diferencia con un ejemplo

#### Scenario: Columna "Programa" formatea correctamente
- **WHEN** un hit tiene `recorded_at = 2026-09-07 14:30:00`
- **THEN** la columna "Programa" muestra `2026-09-07 14:30`

#### Scenario: "Detectado" con estilo secundario
- **WHEN** un hit tiene `matched_at = 2026-09-09 17:18`
- **THEN** la columna "Detectado" muestra `2026-09-09 17:18` con color gris (text-slate-400) y fuente menor (text-xs)

### Requirement: Export CSV respeta el toggle

El job `MentionsExportJob` SHALL recibir y aplicar el `date_field` elegido por el cliente. El archivo CSV SHALL contener una columna adicional explícita que indique qué campo de fecha se usó para filtrar (auditoría).

#### Scenario: Export con toggle programa
- **WHEN** el cliente dispara exportación con `date_field=program`
- **THEN** el CSV contiene las mismas filas que ve en pantalla
- **AND** el header del CSV incluye el comentario `# Filtrado por: programa (recorded_at)`

#### Scenario: Export con toggle detección
- **WHEN** el cliente dispara exportación con `date_field=detected`
- **THEN** el CSV contiene las mismas filas que ve en pantalla
- **AND** el header incluye `# Filtrado por: detection (matched_at)`

### Requirement: El cursor de watermarks sigue siendo `finished_at`

El campo `keyword_scan_watermarks.scanned_until` SHALL seguir referenciando `transcriptions.finished_at`, NO `transcriptions.recorded_at`. Razón: el watermark rastrea "hasta dónde llegó el escaneo" (semántica de procesamiento), no la fecha del programa.

#### Scenario: Catch-up procesa transcripciones por finished_at
- **WHEN** el cron de avisos detecta un par `(keyword, storage)` con `scanned_until < MAX(finished_at) del storage`
- **THEN** procesa esas transcripciones y avanza `scanned_until` hasta ese `finished_at`
- **AND** el filtro NO usa `recorded_at` para decidir qué escanear

### Requirement: Performance aceptable

La columna `recorded_at` SHALL tener un índice parcial `(recorded_at) WHERE recorded_at IS NOT NULL` para acelerar las queries de histórico. El query del Histórico SHALL poder ejecutarse en <500ms para clientes con 10k+ hits visibles.

#### Scenario: Query con 10k hits retorna en <500ms
- **GIVEN** un cliente con 10000 hits visibles
- **WHEN** ejecuta Mis Avisos → Histórico con rango de 60 días, sin filtros adicionales
- **THEN** la respuesta llega en <500ms (medido en `EXPLAIN ANALYZE`)
