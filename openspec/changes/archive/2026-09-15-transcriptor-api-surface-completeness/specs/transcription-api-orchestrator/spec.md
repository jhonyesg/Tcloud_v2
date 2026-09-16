# Spec Delta — transcription-api-orchestrator

## ADDED Requirements

### Requirement: Idempotency key para dedupe upstream
El sistema SHALL calcular `idempotency_key = hash_file('sha256', audioPath)` y enviarlo en el multipart de `POST /v1/transcribe` cuando `SystemSetting('submit_with_idempotency_key')=true` (default true). El upstream deduplica y devuelve el job existente si recibe una clave repetida dentro de su ventana de dedup; el cliente registra el campo `deduplicated=true` en log informativo cuando aparezca en la respuesta, sin cambiar la fila local (mismo job_id).

#### Scenario: Mismo archivo reenviado por el regulador
- **WHEN** el tick reintenta la misma fila tras un 503 transitorio
- **AND** el cliente recibe 200 con `{job_id: "<mismo>", deduplicated: true}`
- **THEN** el cliente persiste el `job_id` igual al anterior, no crea transcription nueva, y loguea `transcription:submit deduplicated=true tx={id}`
- **AND** la respuesta del usuario no cambia: la fila queda `state=queued` igual que un envío normal

#### Scenario: Archivo nuevo envía clave nueva
- **WHEN** el SHA256 del wav nuevo nunca fue visto por el upstream
- **THEN** el upstream responde normalmente con `{job_id: "<nuevo>", deduplicated: false}` o sin el campo
- **AND** la fila queda `state=queued` con job_id nuevo

### Requirement: Re-encolar upstream de jobs zombies vía `unstick`
El sistema SHALL exponer `POST /ia/api-transcriptor/jobs/{id}/unstick` que, para una `Transcription` en `state=processing` con `started_at < now()->subMinutes(15)` y `job_id` no nulo, invoca `POST {base_url}/v1/jobs/{job_id}/unstick` en el upstream, deja registro en log con actor y timestamps, y devuelve `{upstream: {...}}`. La respuesta upstream es libre (un `unstick` exitoso mueve el job de processing → queued en el upstream, que el polling cerrará la próxima vez que consulte).

#### Scenario: Job processing zombie se re-encola
- **WHEN** el operador hace clic en "Re-encolar upstream" sobre un job que lleva >15 min en `state=processing`
- **THEN** el sistema llama `TranscriptorApiClient::unstickUpstream(job_id, node_url)`
- **AND** loguea `unstick admin action tx={id} job_id={jobId} actor={user_id}`
- **AND** el próximo poll (≤30 s) ve `state=queued` upstream y lo procesa con normalidad

#### Scenario: Job processing no-zombie se protege
- **WHEN** un job lleva <15 min en `state=processing`
- **THEN** el botón "Re-encolar upstream" NO se renderiza en la UI
- **AND** el endpoint rechaza con 409 si lo invocan por ruta directa

### Requirement: Eliminar upstream + local con `delete_upstream`
El sistema SHALL exponer `POST /ia/api-transcriptor/jobs/{id}/delete-upstream` que llama `DELETE {base_url}/v1/jobs/{job_id}` y luego borra la fila local con cascade `transcription_segments`. Restringido a estados terminales (`done`, `error`, `dead`, `cancelled`).

#### Scenario: Delete upstream exitoso limpia local
- **WHEN** el operador borra un job `state=dead` con `job_id` válido
- **THEN** el sistema llama `TranscriptorApiClient::deleteUpstream(job_id, node_url)` y, si responde 200/204, elimina la fila local
- **AND** los segmentos asociados se borran en cascade

#### Scenario: Delete upstream falla, no se borra local
- **WHEN** el upstream responde 5xx o timeout
- **THEN** el sistema registra el error y deja la fila local intacta
- **AND** un retry manual posterior puede intentarlo de nuevo

### Requirement: Reintento masivo con `retry-batch`
El sistema SHALL exponer `POST /ia/api-transcriptor/retry-batch` (background via `RunsBackgroundCommands::execBackground`) que invoca el comando `transcription:retry-batch-upstream`, captura los counters `requeued/skipped/failed` que devuelve el upstream (`POST {base_url}/v1/jobs/retry-batch`), y los persiste en `bg_jobs` para que la UI del módulo los muestre. La respuesta inmediata al operador es HTTP 202 con `{runId, accepted: true}`.

#### Scenario: Retry-batch confirma jobs re-encolados
- **WHEN** el operador hace clic en "Reintentar todos los fallidos"
- **THEN** el sistema lanza el comando en background
- **AND** devuelve `runId` y `accepted: true` en <500 ms
- **AND** el operador puede abrir `/ia/api-transcriptor/batch-status/{runId}` para ver progreso en vivo y los counters finales al terminar

#### Scenario: Retry-batch en dry-run no muta nada
- **WHEN** el operador corre `transcription:retry-batch-upstream --dry-run`
- **THEN** el comando cuenta y agrupa por motivo, no llama al upstream, no cambia ninguna fila
- **AND** es seguro correrlo lunes a las 04:00 desde cron sin efectos secundarios

### Requirement: Cancelación upstream centralizada en cliente
El sistema SHALL exponer `TranscriptorApiClient::cancelUpstream(string $jobId, string $nodeUrl = ''): array` y SHALL usarlo desde `ApiTranscriptorController::cancelJob()` (linea 633+) en lugar del `Http::timeout()->post(...)` directo. El comportamiento esperado es idéntico: `POST {base_url}/v1/jobs/{job_id}/cancel` y tratar 200 como éxito.

#### Scenario: Cancelación llama al cliente una sola vez
- **WHEN** el operador cancela un job `state=queued` desde la UI
- **THEN** `grep "v1/jobs/.*/cancel"` en `app/app/` devuelve exactamente 1 hit dentro de `TranscriptorApiClient.php`
- **AND** `ApiTranscriptorController::cancelJob()` invoca `TranscriptorApiClient::cancelUpstream()` y maneja la respuesta uniformemente
