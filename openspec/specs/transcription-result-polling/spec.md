## ADDED Requirements

### Requirement: Polling retrieves transcription results
El sistema SHALL consultar periódicamente el estado de las `Transcription` en `state=queued` o `state=processing` que tengan `job_id`, mediante `GET /v1/jobs/{job_id}` al transcriptor externo, sin depender de webhook entrante.

#### Scenario: Job is done
- **WHEN** el polling consulta un job y el transcriptor responde `state=done` con `srt_url`
- **THEN** el sistema descarga el SRT completo vía `GET {srt_url}`
- **AND** parsea el SRT en segmentos, aplica correcciones y dispara el matching de keywords (si `generate_alerts=true`)
- **AND** marca la `Transcription` en `state=done` con `finished_at=now`, `srt_content`, `duration_seconds` y `word_count`

#### Scenario: Job failed upstream
- **WHEN** el transcriptor responde `state=error`, `state=dead` o `state=cancelled`
- **THEN** el sistema marca la `Transcription` en `state=error` (o `dead`) con el `error_message` del remoto y `finished_at=now`

#### Scenario: Job still in progress
- **WHEN** el transcriptor responde `state=queued` o `state=processing`
- **THEN** el sistema deja la `Transcription` en su estado actual para reintentar en el próximo ciclo del polling

### Requirement: Polling cadence
El sistema SHALL ejecutar el polling cada `poll_interval_seconds` (configurable, default 30s) vía schedule artisan, con `withoutOverlapping` para evitar ejecuciones concurrentes.

#### Scenario: Normal cadence
- **WHEN** el schedule dispara `transcription:poll-results`
- **THEN** el sistema consulta todos los jobs `queued`/`processing` con `job_id` y procesa los `done`/`error`/`dead`
- **AND** finaliza antes del siguiente ciclo sin solaparse

### Requirement: Polling uses persisted node_url
El sistema SHALL usar el `node_url` persistido en la `Transcription` para construir el endpoint de consulta, permitiendo que el mapeo job_id↔nodo sobreviva a reinicios del orquestador.

#### Scenario: Orquestador restarted
- **WHEN** el orquestador se reinicia y hay `Transcription` en `queued` con `job_id` y `node_url`
- **THEN** el polling reconstruye el endpoint `GET {node_url}/v1/jobs/{job_id}` desde la DB y continúa consultando sin pérdida de mapeo

### Requirement: Polling handles unreachable node
El sistema SHALL tolerar fallos de conectividad al nodo sin marcar las transcripciones como error, para permitir reintentos en ciclos posteriores.

#### Scenario: Node temporarily unreachable
- **WHEN** el `GET /v1/jobs/{job_id}` falla por timeout o conexión rechazada
- **THEN** el sistema registra el error en log y deja la `Transcription` en su estado actual (sin cambiar a `error`)
- **AND** reintenta en el próximo ciclo

### Requirement: Polling re-submits stale pending jobs
El sistema SHALL reenviar al transcriptor las `Transcription` en `state=pending` sin `job_id` que excedan `stale_after_minutes` (configurable, default 30), asumiendo que el envío síncrono falló.

#### Scenario: Pending job stuck without job_id
- **WHEN** una `Transcription` en `state=pending` sin `job_id` supera `stale_after_minutes` desde `created_at`
- **THEN** el sistema la procesa como un envío nuevo (ffmpeg + POST) en el próximo ciclo de `scan-and-submit`

### Requirement: Polling fetches SRT from absolute srt_url
El sistema SHALL descargar el SRT usando la `srt_url` devuelta por el transcriptor tal cual (URL absoluta con host y puerto), sin prefijar el `base_url` configurado.

#### Scenario: srt_url is absolute
- **WHEN** el transcriptor devuelve `srt_url="http://192.168.0.138:9000/v1/jobs/{id}/srt"`
- **THEN** el sistema hace `GET` directamente a esa URL absoluta para descargar el SRT

#### Scenario: srt_url is relative
- **WHEN** el transcriptor devuelve `srt_url` relativa (ej. `/v1/jobs/{id}/srt`)
- **THEN** el sistema la prefija con el `node_url` persistido en la `Transcription` para construir la URL absoluta