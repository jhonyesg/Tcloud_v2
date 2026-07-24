## Why

El módulo API transcriptor pierde los resultados del transcriptor externo. El webhook entrante (`/webhooks/transcription`) está roto: el `callback_host` configurado (`http://192.168.0.118`) cae a un vhost "stopped" de nginx que devuelve 404, así que el nodo transcriptor nunca notifica a la app. Como consecuencia, las transcripciones quedan en `state=queued` hasta que `scan-stale` las rescata a los 30 minutos, cuando el ASR real tarda solo ~5 segundos. Adicionalmente, el escaneo de archivos nuevos depende de `storage:sync` (latencia hasta 15 min) y de la tabla `files`, en vez de leer el disco directamente. El resultado: 32-45 minutos de demora total por archivo, con 4196 archivos históricos acumulados sin transcribir.

## What Changes

- **Nuevo scanner de disco directo**: en vez de consultar la tabla `files` poblada por `storage:sync`, el scanner hace `scandir(storage.base_path . '/' . date('dmY'))` y crea/dispara transcripciones para los `.mp4` nuevos, con `filemtime > 60s` como filtro de estabilidad.
- **Recepción por polling como vía principal**: elimina la dependencia del webhook entrante. Un comando de polling consulta `GET /v1/jobs/{job_id}` cada 30s y descarga el SRT cuando el estado es `done`, persistiéndolo en `transcriptions` + `transcription_segments`.
- **Envío síncrono sin Redis**: para el volumen actual (2 storages, ~6 cortes/hora), reemplaza las colas `transcription-high/medium/low` + workers por dos schedules artisan (`scan-and-submit` cada 2 min, `poll-results` cada 30s).
- **`lang_fix=async` fijo**: el nodo entrega SRT corregido en ~5s; se aprovecha esa velocidad con polling frecuente.
- **BREAKING**: se elimina la ruta `POST /webhooks/transcription` (y su controlador `TranscriptionCallbackController`) como vía de recepción. Se elimina `ConvertAndTranscribeJob::dispatchWithPriority` y las colas Redis dedicadas a transcripción.
- **Recuperación de backlog**: el scanner soporta `--days=N` (o `--all`) para procesar carpetas de días anteriores, no solo "hoy".
- **Reutilización de la API del nodo**: se aprovechan los endpoints validados (`/api/info`, `/api/stats`, `/v1/transcribe`, `/v1/jobs/{id}`, `/v1/jobs/{id}/srt`) y se persiste `node_id`/`node_url` por transcripción.

## Non-goals

- No se arregla el enrutamiento nginx del `callback_host` (el webhook queda fuera del diseño).
- No se implementa balanceo de carga multi-nodo (solo se cataloga el nodo único actual).
- No se toca el modelo `Transcription`/`TranscriptionSegment` ni su migración existente (solo se añade `node_id` si falta).
- No se cambia la UI de `/ia/api-transcriptor` (los jobs manuales seguirán funcionando vía los endpoints existentes).
- No se eliminan `CorrectionService`/`KeywordMatcher` (siguen aplicándose al persistir).

## Capabilities

### New Capabilities
- `transcription-disk-scanner`: escaneo directo del filesystem de storages habilitados para descubrir archivos nuevos, crear la relación `File`↔`Transcription` si no existe, y encolar envíos al transcriptor externo.
- `transcription-result-polling`: recepción de resultados del transcriptor externo vía polling de `GET /v1/jobs/{id}` + descarga del SRT, sin depender de webhook entrante.

### Modified Capabilities
<!-- No existen specs previos de transcripción en openspec/specs/, por lo que no hay capabilities a modificar. -->

## Impact

- **Controladores**: `ApiTranscriptorController` (envíos manuales pasan a síncrono puro, sin `dispatchWithPriority`), `TranscriptionCallbackController` (eliminado).
- **Comandos**: nuevos `transcription:scan-and-submit` y `transcription:poll-results`; deprecados `transcription:scan-new`, `transcription:scan-stale`, `transcription:process-batch`.
- **Jobs**: `ConvertAndTranscribeJob` se refactoriza a servicio síncrono o se elimina la lógica de cola.
- **Servicios**: `TranscriptorApiClient` (sin cambios de API, ya soporta submit/getJob/getSrt), `TranscriptionProcessor` (reutilizado), `StorageSyncService` (ya no alimenta al scanner).
- **Schedule** (`routes/console.php`): reemplaza `scan-new`/`scan-stale` por los dos nuevos comandos.
- **Config** (`config/transcriptor.php`): se elimina `callback_host`/`webhook_token`, se añade `poll_interval_seconds`, `scan_days_back`.
- **Migración**: opcional para añadir `transcriptions.node_id` (nullable string) si no existe.
- **Routes** (`routes/web.php:162`): se elimina la ruta del webhook.