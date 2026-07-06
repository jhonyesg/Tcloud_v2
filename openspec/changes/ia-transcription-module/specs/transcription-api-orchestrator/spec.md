## ADDED Requirements

### Requirement: Admin puede habilitar transcripción por StorageProvider
El sistema SHALL permitir al admin marcar cada `StorageProvider` con la bandera `transcription_enabled = true` para indicar que sus archivos deben enviarse a la API de transcripción.

#### Scenario: Admin habilita un storage para transcripción
- **WHEN** el admin edita un StorageProvider y marca `transcription_enabled = true` con `transcription_priority` numérico
- **THEN** el sistema persiste ambos campos y el scanner comienza a considerar archivos de ese storage en el siguiente ciclo

#### Scenario: Admin deshabilita un storage
- **WHEN** el admin marca `transcription_enabled = false`
- **THEN** el scanner deja de crear nuevos `ConvertAndTranscribeJob`s para ese storage, pero los jobs ya encolados o en proceso continúan

---

### Requirement: Scanner detecta archivos nuevos accesibles en storages habilitados
El sistema SHALL ejecutar el command `transcription:scan-new` cada 2-3 minutos vía Laravel scheduler, identificando archivos válidos sin transcripción previa en cada StorageProvider con `transcription_enabled = true`.

#### Scenario: Archivo elegible detectado
- **WHEN** existe un `File` con `modified_at < NOW() - 60s` (archivo "completo" hace >60s) y NO existe una `Transcription` con su `file_id`
- **THEN** el scanner encola un `ConvertAndTranscribeJob` para ese File y registra la fecha de despacho

#### Scenario: Archivo recién modificado se ignora
- **WHEN** un File tiene `modified_at >= NOW() - 60s` (aún se está escribiendo)
- **THEN** el scanner NO lo encola en este ciclo; aparecerá en el siguiente ciclo una vez que pasen los 60s sin modificación

#### Scenario: Archivo ya transcrito se ignora
- **WHEN** ya existe una `Transcription` (en cualquier estado) para un File
- **THEN** el scanner NO encola otro job para ese File

---

### Requirement: ConvertAndTranscribeJob convierte a Opus 64k mono 16kHz
El sistema SHALL convertir el archivo de entrada a Opus 64 kbps, mono, 16 kHz usando ffmpeg local antes de enviarlo a la API, cumpliendo con el formato recomendado por la documentación del transcriptor.

#### Scenario: Conversión exitosa
- **WHEN** el job recibe un File path válido y ffmpeg está disponible en el sistema
- **THEN** se genera un archivo `.opus` temporal de ~7 MB para 15 min de audio (vs ~205 MB del MP4 original) y se elimina al terminar

#### Scenario: ffmpeg falla o no está instalado
- **WHEN** el proceso ffmpeg retorna exit code != 0 o el binario no existe
- **THEN** el job lanza `RuntimeException`, Laravel marca el job como `failed`, y se registra `error_message` en la `Transcription`

---

### Requirement: Job envía archivo a la API con callback URL
El sistema SHALL hacer `POST /v1/transcribe` en `TRANSCRIPTOR_BASE_URL` con multipart `file`, `language=es`, `lang_fix=async` y `callback_url` apuntando a `https://{tcloud-host}/webhooks/transcription`.

#### Scenario: Envío exitoso crea Transcription
- **WHEN** la API responde 200 con `{"job_id":"...","priority":N,"state":"queued"}`
- **THEN** se persiste una `Transcription` con `state=queued`, el `job_id` devuelto, el `node_url` usado y `created_at = NOW()`

#### Scenario: API responde 401 sin Authorization
- **WHEN** `TRANSCRIPTOR_API_KEY` no está configurado y la API requiere token
- **THEN** el job falla con mensaje "API auth required" y se loguea en `error_message`

---

### Requirement: Webhook de transcripción actualiza el estado y persiste el SRT
El sistema SHALL exponer `POST /webhooks/transcription` validando `X-Webhook-Token: TRANSCRIPTOR_WEBHOOK_TOKEN`, descargar el SRT final, parsearlo y guardar segmentos vinculados.

#### Scenario: Webhook done descarga SRT y crea segmentos
- **WHEN** el servidor externo hace POST con `{job_id, state:"done", srt_url:"/v1/jobs/{id}/srt"}` y el token es válido
- **THEN** el handler descarga el SRT, lo guarda en `transcriptions.srt_content`, lo parsea en `TranscriptionSegment` con `start_seconds`, `end_seconds` y `text`, y actualiza `state=done`, `finished_at=NOW()`, `duration_seconds`

#### Scenario: Webhook con token inválido
- **WHEN** el header `X-Webhook-Token` no coincide con `TRANSCRIPTOR_WEBHOOK_TOKEN`
- **THEN** el sistema responde 401 sin tocar la base de datos

#### Scenario: Webhook con state=error|dead
- **WHEN** el POST trae `state="error"` o `"dead"`
- **THEN** el sistema marca la `Transcription` con el mismo estado y guarda `error_message` del payload

---

### Requirement: Polling de respaldo recupera webhooks perdidos
El sistema SHALL correr `transcription:scan-stale` cada 5 minutos vía Laravel scheduler, buscando `Transcription` con `state IN (queued, processing)` y `created_at < NOW() - 30 min` que aún no recibieron webhook, y consultando `GET /v1/jobs/{id}` para actualizar su estado.

#### Scenario: Job pasó a done pero el webhook se perdió
- **WHEN** una `Transcription` lleva más de 30 min en `processing` sin webhook
- **THEN** el polling consulta `GET /v1/jobs/{job_id}` en el `node_url` registrado; si state=done, descarga SRT y procesa como si fuera el webhook

#### Scenario: Job está dead según la API externa
- **WHEN** el polling detecta que la API externa marcó el job como `dead`
- **THEN** la `Transcription` se actualiza con `state=dead` y `error_message="dead in upstream"`

---

### Requirement: Admin puede ver transcriptions recientes y sus detalles
El sistema SHALL listar las transcripciones en `/ia/api-transcriptor` y permitir ver el detalle de una en `/ia/api-transcriptor/jobs/{id}` con el SRT, segmentos y SRT viewer (texto plano).

#### Scenario: Listado paginado de jobs
- **WHEN** el admin abre `/ia/api-transcriptor`
- **THEN** ve los últimos 100 jobs ordenados por `created_at DESC` con columnas: filename, file_id, state, duration_seconds, started_at, finished_at

#### Scenario: Detalle muestra SRT
- **WHEN** el admin abre el detalle de un job en estado `done`
- **THEN** ve el `srt_content` formateado en un `<pre>` con scroll y un botón "Descargar .srt"

---

### Requirement: Admin puede re-encolar un job fallido manualmente
El sistema SHALL exponer acción "Reintentar" en el detalle de un job en estado `error` o `dead` que re-encola un `ConvertAndTranscribeJob` borrando la `Transcription` previa.

#### Scenario: Reintentar un job error
- **WHEN** el admin hace POST a `/ia/api-transcriptor/jobs/{id}/retry` con state ∈ {error, dead}
- **THEN** se elimina la `Transcription` anterior y se encola un nuevo job (la API externa puede haber purgado el `.bin` tras 7 días, en cuyo caso el nuevo job fallará con "file not found in upstream")

#### Scenario: Reintentar un job en estado terminal inválido
- **WHEN** el job está en `done`, `queued` o `processing`
- **THEN** el sistema responde 409 "solo se reintentan jobs en error/dead"
