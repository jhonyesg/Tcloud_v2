# Delta: transcription-api-orchestrator

## MODIFIED Requirements

### Requirement: Admin puede ver transcriptions recientes y sus detalles

El sistema SHALL listar las transcripciones en `/ia/api-transcriptor` de forma **paginada y
filtrada en servidor** por sub-tab, y permitir ver el detalle de una en
`/ia/api-transcriptor/jobs/{id}` con el SRT, los segmentos y el SRT viewer (texto plano).

Además SHALL permitir leer la transcripción sin abandonar el listado, mediante la acción
"Ver transcripción" que consume `GET /ia/api-transcriptor/jobs/{id}/transcript` (ver
capability `transcriptor-jobs-listing`).

> **Historia.** El requisito original decía "los últimos 100 jobs ordenados por
> `created_at DESC`", implementado como `limit(200)` sin paginación y con las sub-tabs
> repartiendo esas filas en el cliente. Con 84.763 filas en `queued`, la ventana no contenía
> ni una sola de las 88.514 transcripciones en `done`.

#### Scenario: Listado paginado de jobs
- **WHEN** el admin abre `/ia/api-transcriptor`
- **THEN** ve la primera página de 50 trabajos del scope activo, con columnas filename,
  file_id, state, duration_seconds, started_at, finished_at
- **AND** dispone de controles Anterior/Siguiente hasta 500 registros por scope

#### Scenario: Detalle muestra SRT y segmentos
- **WHEN** el admin abre el detalle de un job en estado `done`
- **THEN** ve la lista de segmentos con sus marcas de tiempo, el `srt_content` formateado en
  un `<pre>` con scroll, y un botón "Descargar .srt" que descarga el contenido real
