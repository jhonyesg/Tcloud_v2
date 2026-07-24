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

### Requirement: Layout-aware storage scan
El sistema SHALL soportar dos layouts de almacenamiento para el scanner automático: `flat` (default, comportamiento actual: `base_path/dmY/*`) y `grouped_by_subfolder` (storages consolidados: `base_path/<subcarpeta>/dmY/*`). El layout se determina por la columna `storage_providers.folder_layout` y se elige la estrategia de descubrimiento de carpetas en consecuencia.

#### Scenario: Flat layout (canales TV)
- **WHEN** un storage tiene `folder_layout='flat'`
- **THEN** el scanner busca archivos en `base_path/dmY/` (comportamiento histórico)
- **AND** esta rama se preserva como backward-compatible para todos los storages existentes que no actualicen la columna

#### Scenario: Grouped layout (emisoras consolidadas)
- **WHEN** un storage tiene `folder_layout='grouped_by_subfolder'`
- **THEN** el scanner busca archivos en `base_path/*/dmY/` iterando cada subcarpeta inmediata del base_path
- **AND** para cada subcarpeta con `dmY/` legible, escanea los archivos multimedia contenidos
- **AND** los archivos `.mp4`, `.mp3`, `.mkv`, `.opus`, `.flac`, `.wav`, `.aac` son tratados como candidatos

### Requirement: Scope-aware deduplication via parent-child storage resolution
El sistema SHALL, antes de iterar carpetas de un storage, calcular los subpaths excluidos: el conjunto de primeros segmentos de path (relativos al `base_path` del storage actual) que coinciden con el `base_path` de otros storages con `transcription_enabled=true` y `allow_parent_overlap=false`. El scanner omitirá completamente las carpetas que caen bajo esos segmentos.

#### Scenario: Parent storage with child storage enabled
- **WHEN** el storage A tiene `base_path=/foo/` y el storage B tiene `base_path=/foo/LA_W/`, ambos con `transcription_enabled=true`
- **AND** el scanner procesa el storage A
- **THEN** el scanner detecta que `LA_W` es subpath de B
- **AND** omite la carpeta `/foo/LA_W/dmY/` completa del scan de A
- **AND** registra un log `DiskScanner: skip /foo/LA_W/18072026 (storage hijo toma control)`

#### Scenario: Parent storage with no children
- **WHEN** el scanner procesa un storage sin descendientes con `transcription_enabled=true`
- **THEN** no se excluye ningún subpath y el scan procede normalmente

#### Scenario: Allow parent overlap override
- **WHEN** un storage tiene `allow_parent_overlap=true`
- **THEN** el scanner NO excluye subpaths de storages hijos (escape hatch para casos de uso que requieren duplicación intencional)

### Requirement: Absolute-path owner detection prevents cross-storage duplication
El sistema SHALL, antes de crear una nueva fila en `files` para un candidato descubierto, verificar si el `absolute_path` (= `storage_provider.base_path + '/' + file.path`) ya está registrado bajo cualquier OTRO storage. Si lo está, el scanner omite la creación sin reportar error, dejando al storage ya existente como dueño único del archivo.

#### Scenario: File already registered under another storage
- **WHEN** el scanner procesa un archivo candidato cuyo `absolute_path` coincide con un `File` existente bajo otro storage
- **THEN** el scanner omite la creación de un nuevo `File` y `Transcription`
- **AND** registra log `DiskScanner: skip <absolute_path> (dueño: storage <id> <name>)`
- **AND** el archivo mantiene su dueño actual (owner único)

#### Scenario: File not registered anywhere
- **WHEN** el scanner procesa un archivo candidato cuyo `absolute_path` no coincide con ningún `File` existente
- **THEN** el scanner crea el `File` y la `Transcription(pending)` normalmente

### Requirement: Scanner respects layout and dedup rules in single-tenant and multi-tenant configurations
El sistema SHALL combinar las reglas de layout-aware y scope-aware dedup para soportar las configuraciones operativas reales: storages individuales (TV canales) coexistiendo con storages consolidados (emisoras) y con storages específicos (radios individuales), sin generar duplicación.

#### Scenario: Emisoras consolidated + specific radio both enabled
- **WHEN** storage 47 (`01 Emisoras 01`, `grouped_by_subfolder`) y storage 63 (`03 La W Bogota`, `flat`) están ambos habilitados
- **THEN** storage 47 escanea todas las subcarpetas excepto `LA_W/` (excluida por descendencia)
- **AND** storage 63 escanea `LA_W/dmY/` pero omite archivos cuyo `absolute_path` ya está bajo storage 47
- **AND** el resultado neto es 1 archivo = 1 `File` row = 1 `Transcription` row

#### Scenario: Consolidated enabled, specific disabled
- **WHEN** storage 47 está habilitado y storage 63 no
- **THEN** storage 47 escanea todas las subcarpetas incluida `LA_W/`
- **AND** los archivos LA_W se registran bajo storage 47 (no se omite por descendencia porque el hijo no está enabled)