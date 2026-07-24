## ADDED Requirements

### Requirement: Disk scanner discovers new recordings
El sistema SHALL escanear directamente el filesystem de cada `StorageProvider` con `transcription_enabled=true`, leyendo la carpeta del día actual (`base_path . '/' . date('dmY')`) para descubrir archivos `.mp4` nuevos, sin depender de la tabla `files` poblada por `storage:sync`.

#### Scenario: New MP4 found in today folder
- **WHEN** el scanner corre y existe un `.mp4` en `base_path/dmY/` con `filemtime()` anterior a `now - scan_min_age_seconds`
- **THEN** el sistema verifica si existe un `File` con `path='dmY/name'`; si no existe, lo crea con el `file_modified_at` real del disco
- **AND** verifica si existe una `Transcription` con ese `file_id`; si no existe, la crea en `state=pending` sin `job_id`

#### Scenario: File still being written
- **WHEN** el scanner encuentra un `.mp4` con `filemtime()` posterior a `now - scan_min_age_seconds`
- **THEN** el sistema lo ignora en este ciclo (aún está siendo escrito por el grabador)

#### Scenario: Today folder does not exist
- **WHEN** la carpeta `base_path/dmY/` no existe
- **THEN** el sistema no reporta candidatos para ese storage y continúa con el siguiente

### Requirement: Scanner supports backlog recovery
El sistema SHALL soportar un parámetro `--days=N` para escanear también las carpetas de los N días anteriores al actual, y `--all` para escanear todas las carpetas existentes bajo `base_path`.

#### Scenario: Recover yesterday recordings
- **WHEN** se ejecuta el scanner con `--days=1`
- **THEN** el sistema escanea la carpeta de hoy y la de ayer, procesando los `.mp4` sin transcripción de ambas

#### Scenario: Recover all historical backlog
- **WHEN** se ejecuta el scanner con `--all`
- **THEN** el sistema escanea recursivamente todas las carpetas bajo `base_path` que contengan `.mp4` sin transcripción, respetando `scan_batch` por ciclo

### Requirement: Scanner submits pending transcriptions synchronously
El sistema SHALL, para cada `Transcription` en `state=pending` sin `job_id`, ejecutar síncronamente la conversión a Opus (`ffmpeg`) y el envío al transcriptor externo vía `POST /v1/transcribe`, sin usar colas Redis.

#### Scenario: Submit a pending transcription
- **WHEN** existe una `Transcription` en `state=pending` sin `job_id` y su `File` es legible en disco
- **THEN** el sistema convierte el archivo a Opus 64k mono 16kHz en `/dev/shm` (fallback a `sys_get_temp_dir`)
- **AND** envía el Opus al transcriptor con `lang_fix=async` y `language=es` (sin `callback_url`)
- **AND** guarda el `job_id`, `node_url` y `node_id` devueltos en la `Transcription`
- **AND** marca la `Transcription` en `state=queued`

#### Scenario: File not readable
- **WHEN** el archivo fuente no existe o no es legible en disco
- **THEN** el sistema marca la `Transcription` en `state=error` con mensaje descriptivo y continúa con el siguiente

### Requirement: Scanner respects batch limit
El sistema SHALL limitar la cantidad de archivos procesados por ciclo al valor `scan_batch` (configurable), ordenados por `file_modified_at` descendente.

#### Scenario: Batch limit reached
- **WHEN** hay más candidatos pendientes que `scan_batch`
- **THEN** el sistema procesa solo los `scan_batch` más recientes y deja el resto para el siguiente ciclo

### Requirement: Scanner skips already-transcribed files
El sistema SHALL omitir cualquier archivo que ya tenga una fila en `transcriptions`, independientemente de su estado.

#### Scenario: File already has transcription
- **WHEN** el scanner encuentra un `.mp4` cuya `file_id` ya existe en `transcriptions`
- **THEN** el sistema lo omite sin crear duplicados