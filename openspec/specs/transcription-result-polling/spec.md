## Purpose

Capability `transcription-result-polling`: consolida los requisitos y comportamientos de polling retrieves transcription results en TCloud. (Purpose derivado automáticamente al normalizar el formato legacy del spec.)

## Requirements

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

### Requirement: Estado `corrected` del upstream se persiste y rehidrata
El sistema SHALL persistir en `transcriptions.corrected` el campo homónimo que devuelve `GET /v1/jobs/{job_id}` (0=en proceso, 1=corrector aplicado, -1=corrector falló), así como `corr_pass` y `corr_mms` cuando estén disponibles. La persistencia SHALL ocurrir dentro de la lógica unificada `applyRemoteState(Transcription, array)` que es llamada por el polling y por el webhook (ver capability `transcriptor-webhook-notifications` cuando se active).

#### Scenario: Polling rehidrata `corrected`
- **WHEN** el polling consulta un job local `state=done` con `corrected=null` por no haber tenido `corrected` cuando se cerró
- **AND** el upstream devuelve `{state: "done", corrected: 1, corr_pass: 12, corr_mms: 8}`
- **THEN** `applyRemoteState` actualiza `corrected=1`, `corr_pass=12`, `corr_mms=8` sin re-procesar SRT ni alertas
- **AND** se loguea `transcription:corrected-updated tx={id} corrected=1 corr_pass=12 corr_mms=8`

#### Scenario: Webhook entrega `corrected` antes que el poll
- **WHEN** el upstream notifica vía webhook `{state: "done", corrected: 0, ...}`
- **AND** el polling consultó la misma fila hace 5 s con `corrected=null`
- **THEN** `applyRemoteState` setea `corrected=0` desde webhook, marca `last_polled_at=now`
- **AND** al llegar el siguiente poll (≤30 s después) la respuesta es idempotente: no re-procesa

### Requirement: Aplicar estado remoto es idempotente
El sistema SHALL garantizar que `applyRemoteState(Transcription, array)` puede invocarse múltiples veces con la misma respuesta sin duplicar procesos, alertas ni SRT.

#### Scenario: Race entre webhook y poll sobre la misma fila
- **WHEN** el webhook entrega `{state: "done", corrected: 1, srt_url: "..."}` y simultáneamente otro poll ya hizo `getJob` y está iniciando `processDoneWithSrt`
- **THEN** solo una de las dos invocaciones entra al flujo SRT; la otra ve `state=done` local y la respuesta y sale
- **AND** ningún segmento se inserta dos veces (`UNIQUE (transcription_id, segment_index)` en BD impide además duplicados físicos)

### Requirement: Backfill de `corrected` corrige legados
El sistema SHALL exponer `php artisan transcription:backfill-corrected-audit [--days=7] [--dry-run] [--limit=500]` que recorre las filas `state=done` con `corrected IS NULL`, llama `GET /v1/jobs/{job_id}` para cada una, y persiste `corrected`, `corr_pass`, `corr_mms` según respuesta upstream.

#### Scenario: Dry-run cuenta cuántos rehidrataría
- **WHEN** el operador corre con `--dry-run`
- **THEN** imprime `Candidate count: N` y desglose por `finished_at` window
- **AND** no llama al upstream, no escribe en BD

#### Scenario: Backfill idempotente
- **WHEN** se corre dos veces seguidas
- **THEN** la segunda pasada no actualiza filas cuya columna `corrected` ya está setada
- **AND** termina rápido porque el filtro `WHERE corrected IS NULL` solo trae los aún-null

### Requirement: Visibilidad del estado `corrected` en la UI del Transcriptor
El sistema SHALL añadir a `/ia/api-transcriptor` una columna "Corrector" en la tabla principal y un panel lateral en el detalle del job que muestre `corrected`, `corr_pass`, `corr_mms`, y `last_polled_at`. La columna SHALL colorearse con: gris (sin auditar), azul animado (pending `corrected=0`), verde (`corrected=1`), ámbar (`corrected=-1`, "corrector falló — SRT usable").

> El sub-indicador "Done X / Y" en el dashboard global (TE6) quedó **diferido**: requiere un endpoint propio para contar done/corrected sin acoplar el widget a la BD. La columna de la tabla y el panel de detalle cubren el caso de uso principal.

#### Scenario: Operador distingue calidad del SRT
- **WHEN** el operador abre la tabla de jobs `state=done`
- **THEN** la columna "Corrector" muestra color distinto según `corrected`
- **AND** el caso `corrected=-1` muestra tooltip "Corrector falló; SRT sin post-proceso, aún usable" porque la doc del upstream lo dice así