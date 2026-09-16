# Delta: jobs-stuck-refresh-bulk

## MODIFIED Requirements

### Requirement: Stuck-job refresh endpoint

El sistema SHALL exponer `POST /ia/api-transcriptor/jobs/{id}/refresh-status`, que carga la
`Transcription`, llama al helper `syncFromUpstream()` para consultar la API externa por el
`job_id` de la fila, y devuelve el estado local actualizado.

El destino de la fila tras el refresco SHALL respetar la separación en tres sub-tabs: las
que pasan a `done` van a **Completados**, y las que pasan a `error` o `dead` van a
**Fallidos** (antes ambas iban a Completados).

#### Scenario: Refrescar un job que terminó upstream pero perdió el webhook
- **WHEN** un admin hace POST sobre un job en `queued` con `job_id` no nulo y la API externa
  responde `state = 'done'`
- **THEN** el controlador actualiza la fila a `done`, descarga el SRT por la vía
  `processDone()`, puebla `transcription_segments`, ejecuta `KeywordMatcher` y devuelve 200
- **AND** la fila pasa a la sub-tab **Completados** en el siguiente `load()`

#### Scenario: Refrescar un job que sigue procesándose upstream
- **WHEN** la API externa responde `state = 'processing'`
- **THEN** el controlador refleja ese estado local y devuelve 200
- **AND** la fila permanece en la sub-tab Pendientes

#### Scenario: Refrescar un job que falló upstream
- **WHEN** la API externa responde `state ∈ {error, dead}`
- **THEN** el controlador marca la fila con ese estado, el mensaje de error remoto y
  `finished_at = now()`
- **AND** la fila pasa a la sub-tab **Fallidos**

#### Scenario: Refrescar una fila sin job_id
- **WHEN** un admin hace POST sobre una fila con `job_id` nulo
- **THEN** el controlador devuelve 422 explicando que la fila nunca se envió upstream, y no
  hace ninguna llamada externa

#### Scenario: API externa inalcanzable
- **WHEN** la llamada a la API externa lanza excepción
- **THEN** el controlador loguea el error y devuelve 502 con un mensaje descriptivo
- **AND** el estado local no cambia
