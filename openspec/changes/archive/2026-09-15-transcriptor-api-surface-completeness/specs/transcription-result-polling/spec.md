# Spec Delta — transcription-result-polling

## ADDED Requirements

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

#### Scenario: Operador distingue calidad del SRT
- **WHEN** el operador abre la tabla de jobs `state=done`
- **THEN** la columna "Corrector" muestra color distinto según `corrected`
- **AND** el caso `corrected=-1` muestra tooltip "Corrector falló; SRT sin post-proceso, aún usable" porque la doc del upstream lo dice así

#### Scenario: Header "Done" del dashboard distingue corregidos vs no
- **WHEN** el admin abre el dashboard global con el widget `bg-job-indicator`
- **THEN** aparece un sub-bloque `Done: X / Y` donde Y incluye todos los done y X solo los `corrected=1`
- **AND** muestra también `Corrector fallido: Z` para los `corrected=-1`
