# Spec Deltas — transcriptor-rescan-completed

## ADDED Requirements

### Requirement: Scanner reprocesses completed transcriptions with accessible files

El sistema SHALL, cuando se ejecuta `transcription:scan-and-submit --include-done`, además del comportamiento existente de descubrir archivos sin transcripción (Fase 1) y reintentar fallidos cuando `--include-failed` también está activo (Fase 1.5), recolectar todas las `Transcription` con `state='done'` cuyo archivo asociado sigue accesible en disco (Fase 1.6), resetearlas a `state='pending'`, limpiar `job_id`, `node_url`, `node_id`, `error_message`, `finished_at`, e incrementar el contador `retries`. El campo `srt_content` **SHALL NOT** ser modificado por el scanner: se conserva como fallback mientras el job nuevo está en vuelo. Para cada `Transcription` con `state='done'` cuyo archivo ya no es accesible, SHALL marcarla como `state='dead'` con un mensaje claro.

Cuando el flag `--include-done` se combina con un scope de rango (`--from=DDMMYYYY --to=DDMMYYYY`), la recolección SHALL acotarse a `Transcription` cuyo `finished_at` cae dentro del rango. Sin rango, SHALL aplicar el mismo filtro que la Fase 1 (`--days` o `scan_days_back`).

#### Scenario: Done transcription with accessible file

- **WHEN** el scanner corre con `--include-done` y existe una `Transcription` con `state='done'` cuyo `File` es legible en disco
- **THEN** el scanner actualiza la `Transcription`: `state='pending'`, `error_message=null`, `job_id=null`, `node_url=null`, `node_id=null`, `finished_at=null`, `retries++`
- **AND** el campo `srt_content` conserva el valor previo (no se borra ni se sobreescribe en este paso)
- **AND** la fila será encolada en Redis en la Fase 2 del mismo batch (junto con los pending nuevos y los recovered de failed)

#### Scenario: Done transcription with missing file

- **WHEN** el scanner corre con `--include-done` y existe una `Transcription` con `state='done'` cuyo `File` apunta a una ruta inexistente o no legible
- **THEN** el scanner actualiza la `Transcription`: `state='dead'`, `error_message='Archivo no accesible en disco (<path>). No se reintentará automáticamente.'`, `finished_at=now`
- **AND** la fila NO se reencola a Redis
- **AND** el campo `srt_content` se conserva aunque la fila pase a `dead`

#### Scenario: Done transcription within scope range

- **WHEN** el scanner corre con `--include-done --from=DDMMYYYY --to=DDMMYYYY`
- **THEN** sólo se recolectan `Transcription` con `state='done'` cuyo `finished_at` está dentro del rango
- **AND** el resto de las `done` fuera del rango NO se tocan

#### Scenario: srt_content is overwritten only on successful reprocess

- **WHEN** una `Transcription` con `state='done'` se reprocesa y el job nuevo termina OK en la API externa
- **THEN** `srt_content` se sobreescribe con el nuevo resultado durante el flujo de `TranscriptionSubmitService::submit()`
- **AND** `finished_at` se repobla con el timestamp del nuevo fin

#### Scenario: srt_content is preserved when reprocess fails upstream

- **WHEN** una `Transcription` con `state='done'` se reprocesa y el job nuevo termina en `state='error'` upstream
- **THEN** el `srt_content` viejo permanece intacto (no se borró antes del reenvío)
- **AND** la fila queda en `state='error'` con `retries` ya incrementado por el reset previo
- **AND** en el siguiente batch con `--include-failed`, la fila es elegible para un nuevo reintento usando el `srt_content` viejo como fallback hasta que llegue el nuevo

### Requirement: UI exposes include-done toggle

El sistema SHALL exponer en el modal "Escanear storages" un checkbox "Incluir completados" (default OFF), debajo del checkbox "Reintentar fallidos", con el mismo estilo visual (`bg-amber-50 border-amber-200`) y patrón de tooltip informativo. Cuando está marcado, SHALL enviar `include_done=true` en el body del POST `/ia/api-transcriptor/process-batch`, lo que el backend traduce al flag `--include-done` del comando artisan.

#### Scenario: User marks include-done and starts batch

- **WHEN** el operador marca el checkbox "Incluir completados" y hace clic en "Iniciar procesamiento"
- **THEN** el frontend envía `include_done: true` en el body
- **AND** el backend ejecuta el comando con `--include-done`
- **AND** al terminar el batch, el panel de resultados muestra `done_rescan` con el desglose: candidatos, `reset_to_pending`, `promoted_to_dead`

#### Scenario: Default behavior unchanged

- **WHEN** el operador NO marca el checkbox "Incluir completados"
- **THEN** el frontend omite `include_done` (o lo envía en `false`)
- **AND** el backend NO agrega `--include-done` al comando
- **AND** el comportamiento es idéntico al estado previo al cambio (no se tocan filas `state='done'`)

#### Scenario: include-done combines with include-failed

- **WHEN** el operador marca AMBOS checkboxes "Reintentar fallidos" e "Incluir completados"
- **THEN** el backend ejecuta el comando con `--include-failed --include-done`
- **AND** las filas con `state='error'` se manejan por la Fase 1.5
- **AND** las filas con `state='done'` se manejan por la Fase 1.6
- **AND** ambos grupos terminan en `state='pending'` y son encolados en la Fase 2

### Requirement: Estimation endpoint reports done candidates count

El sistema SHALL, cuando el endpoint `POST /ia/api-transcriptor/scan/estimate` recibe un `mode` (`today`, `range`, `all`) en el body, además de devolver `files_missing`, `error_recoverable` y `dead_irrecoverable` que ya existían, SHALL devolver `done_rescan` con el conteo de `Transcription` con `state='done'` cuyo `finished_at` cae dentro del scope solicitado (o todas si `mode='all'`).

#### Scenario: Today scope includes only today's completed

- **WHEN** el operador elige scope "Hoy" y abre el modal
- **THEN** `batchEstimate.done_rescan` refleja el conteo de `Transcription` con `state='done'` y `finished_at >= now()->startOfDay()`

#### Scenario: Range scope filters by finished_at

- **WHEN** el operador elige scope "Rango" con from/to
- **THEN** `batchEstimate.done_rescan` refleja el conteo de `Transcription` con `state='done'` y `finished_at` dentro del rango

#### Scenario: All scope returns total done count

- **WHEN** el operador elige scope "Histórico"
- **THEN** `batchEstimate.done_rescan` refleja el conteo total de `Transcription` con `state='done'` (sin filtro de fecha)
- **AND** se aplica el mismo guardarraíl `MAX_FILES = 50000` que ya existe para `files_missing`

#### Scenario: Frontend shows done_rescan line when relevant

- **WHEN** `batchEstimate.done_rescan > 0` y el checkbox "Incluir completados" NO está marcado
- **THEN** el modal muestra una línea informativa: `"N transcripciones en done reprocesables marcando 'Incluir completados' abajo"`
- **AND** al marcar el checkbox, la línea permanece visible como confirmación del alcance

## MODIFIED Requirements

Ninguno. Los requirements existentes de `transcription-disk-scanner` (Fase 1, `--include-failed`, scope selector) se mantienen sin cambios. Este delta es puramente aditivo.

## REMOVED Requirements

Ninguno.
