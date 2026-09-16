# Delta — transcription-disk-scanner

## MODIFIED Requirements

### Requirement: Disk scanner discovers new recordings
El sistema SHALL escanear directamente el filesystem de cada `StorageProvider` con `transcription_enabled=true`, leyendo la carpeta del día actual (`base_path . '/' . date('dmY')`) para descubrir archivos `.mp4` nuevos, sin depender de la tabla `files` poblada por `storage:sync` y persistirá marcas de telemetría en la fila Transcription resultante.

Cuando `DiskScannerService::scanStorage()` crea una nueva fila `Transcription` para un archivo candidato recién detectado, **SHALL** poblar la columna `discovered_at` con el timestamp del momento de la creación del registro. Las columnas `dispatched_at`, `submission_committed_at` y `regulator_skip_reason` arrancan en `NULL` en esa misma fila.

Cuando `DiskScannerService::collectFailedCandidates()` (modo `--include-failed`) resetea una `Transcription` desde `state='error'` a `state='pending'`, **SHALL NOT** modificar `discovered_at` ni `dispatched_at`: esos campos reflejan el primer descubrimiento en disco y deben sobrevivir reintentos.

#### Scenario: New MP4 found in today folder
- **WHEN** el scanner corre y existe un `.mp4` en `base_path/dmY/` con `filemtime()` anterior a `now - scan_min_age_seconds`
- **THEN** el sistema verifica si existe un `File` con `path='dmY/name'`; si no existe, lo crea con el `file_modified_at` real del disco
- **AND** verifica si existe una `Transcription` con ese `file_id`; si no existe, la crea en `state=pending` sin `job_id`
- **AND** popula `transcriptions.discovered_at = now()` en la fila recién creada
- **AND** deja `dispatched_at`, `submission_committed_at` y `regulator_skip_reason` en `NULL`

#### Scenario: Fila existente antes del upgrade
- **WHEN** existe ya una `Transcription` para el `file_id` (creada antes de la migracion)
- **THEN** el scanner NO la duplica ni modifica su `discovered_at`
- **AND** la fila sigue siendo elegible para que el tick posterior la marque con `dispatched_at`

#### Scenario: Reintento de fallido preserva discovered_at
- **WHEN** el scanner corre con `--include-failed` y resetea una `Transcription` errored a pending
- **THEN** `state='pending'`, `retries += 1`, `job_id=null`, `error_message=null`
- **AND** `discovered_at`, `dispatched_at`, `submission_committed_at` se conservan con sus valores previos

#### Scenario: File still being written
- **WHEN** el scanner encuentra un `.mp4` con `filemtime()` posterior a `now - scan_min_age_seconds`
- **THEN** el sistema lo ignora en este ciclo (aún está siendo escrito por el grabador)
- **AND** no crea `Transcription` ni setea `discovered_at` hasta el próximo ciclo

#### Scenario: Today folder does not exist
- **WHEN** la carpeta `base_path/dmY/` no existe
- **THEN** el sistema no reporta candidatos para ese storage y continúa con el siguiente
