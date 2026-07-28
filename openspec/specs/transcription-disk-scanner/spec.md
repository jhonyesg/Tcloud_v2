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

### Requirement: Scanner submits pending transcriptions
El sistema SHALL, para cada `Transcription` en `state=pending` sin `job_id`, ejecutar la conversión a Opus (`ffmpeg`) y el envío al transcriptor externo vía `POST /v1/transcribe`.

> **Corrección (2026-07-26)**: este requisito decía «sin usar colas Redis», pero la implementación encola a `queues:transcription` desde antes de este cambio (`ScanAndSubmitCommand` despacha `ConvertAndTranscribeJob` salvo con `--no-dispatch`, y el tick programado usa precisamente `--no-dispatch` para separar descubrimiento de encolado). La ejecución síncrona solo sobrevive en los endpoints manuales de la UI (`transcribeFile`, `dispatchNow`) y en el reenvío de atascados de `poll-results`. El texto se corrige para reflejar la realidad; la divergencia es anterior a este cambio.

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
El sistema SHALL limitar el DESCUBRIMIENTO por storage y por ciclo al valor `scan_batch`, ordenados por `file_modified_at` descendente, y SHALL limitar el ENCOLADO por ejecución a `min(scan_max_dispatch_per_cycle, deficit_del_regulador)`.

Ambos límites son distintos y ambos son necesarios: `scan_batch` acota cuánto se descubre en cada storage; el tope de encolado acota cuánto entra a la cola en total.

#### Scenario: Batch limit reached
- **WHEN** hay más candidatos pendientes que `scan_batch`
- **THEN** el sistema descubre solo los `scan_batch` más recientes y deja el resto para el siguiente ciclo

#### Scenario: Dispatch cap across storages
- **WHEN** el descubrimiento produce candidatos en N storages habilitados
- **THEN** el encolado NO SHALL calcularse como `scan_batch * N`
- **AND** SHALL acotarse a `scan_max_dispatch_per_cycle` (default 200) y además al déficit del regulador
- **AND** si la cola Redis ya está en/sobre el objetivo, SHALL omitir el encolado por completo

> Con 31 storages y `scan_batch=100`, la fórmula anterior encolaba 3100 jobs en un bucle apretado sin consultar al regulador. Es además la ruta del botón «Escanear storages» de la UI, así que la inundación también ocurría con disparo manual.

#### Scenario: Dispatch paused
- **WHEN** `dispatch_paused` está activo
- **THEN** el descubrimiento SHALL completarse con normalidad (no se pierde nada)
- **AND** el encolado SHALL omitirse dejando traza en el log

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

### Requirement: Scanner retries errored transcriptions with accessible files
El sistema SHALL, cuando se ejecuta `transcription:scan-and-submit --include-failed`, además del comportamiento existente de descubrir archivos sin transcripción, recolectar todas las `Transcription` con `state='error'` cuyo archivo asociado sigue accesible en disco y que tengan `retries < max_retries` (configurable, default 3), resetearlas a `state='pending'` e incrementar el contador `retries`. Para cada `Transcription` con `state='error'` cuyo archivo ya no es accesible, SHALL marcarla como `state='dead'` con un mensaje claro.

#### Scenario: Errored transcription with accessible file
- **WHEN** el scanner corre con `--include-failed` y existe una `Transcription` con `state='error'`, `retries < 3`, cuyo `File` es legible en disco
- **THEN** el scanner actualiza la `Transcription`: `state='pending'`, `error_message=null`, `job_id=null`, `node_url=null`, `node_id=null`, `retries++`
- **AND** la fila será encolada en Redis en la Fase 2 del mismo batch (junto con los pending nuevos)

#### Scenario: Errored transcription with missing file
- **WHEN** el scanner corre con `--include-failed` y existe una `Transcription` con `state='error'` cuyo `File` apunta a una ruta inexistente o no legible
- **THEN** el scanner actualiza la `Transcription`: `state='dead'`, `error_message='Archivo no accesible en disco (<path>). No se reintentará automáticamente.'`
- **AND** la fila NO se reencola a Redis

#### Scenario: Errored transcription with max retries reached
- **WHEN** el scanner corre con `--include-failed` y existe una `Transcription` con `state='error'` y `retries >= max_retries` (3 por default)
- **THEN** el scanner NO modifica la fila y la cuenta en estadísticas como `skipped_max_retries`
- **AND** el operador puede reprocesarla manualmente desde la UI si lo desea

#### Scenario: Dead transcriptions are never auto-retried
- **WHEN** el scanner corre con `--include-failed` y existe una `Transcription` con `state='dead'`
- **THEN** el scanner la ignora completamente (no la incluye en candidatos, no modifica el campo retries)
- **AND** la única vía de reprocesamiento para archivos dead es la acción manual desde la UI

### Requirement: Auto-promotion to dead after max retries
El sistema SHALL, cuando `TranscriptionSubmitService::markError()` se invoca para una `Transcription` cuyo `retries >= max_retries`, marcarla automáticamente como `state='dead'` con un mensaje que mencione el límite alcanzado, en lugar de dejarla en `error`. Esto aplica también cuando el reprocess manual falla consecutivamente.

#### Scenario: Worker failure exceeds retry limit
- **WHEN** un worker de Redis falla al transcribir un archivo que ya fue reintentado 3 veces (retries=3 al entrar al worker)
- **THEN** `markError()` actualiza la `Transcription`: `state='dead'`, `error_message='[Auto] Max retries (3) alcanzado. <error original>'`, `retries=4`
- **AND** la fila queda fuera del scope de reintento automático

#### Scenario: First-time failure stays as error
- **WHEN** un worker falla al transcribir un archivo por primera vez (retries=0 al entrar al worker)
- **THEN** `markError()` actualiza la `Transcription`: `state='error'`, `error_message=<error>`, `retries=1`
- **AND** la fila será elegible para reintento en el siguiente batch con `--include-failed`

### Requirement: UI exposes include-failed toggle
El sistema SHALL exponer en el modal "Escanear storages" un checkbox "Reintentar fallidos" (default OFF) que, cuando está marcado, envía `include_failed=true` en el body del POST `/ia/api-transcriptor/process-batch`. El frontend SHALL mostrar en el panel de resultados el desglose: archivos recuperados, promovidos a dead, saltados por max retries.

#### Scenario: User marks include-failed and starts batch
- **WHEN** el operador marca el checkbox "Reintentar fallidos" y hace clic en "Iniciar procesamiento"
- **THEN** el frontend envía `include_failed: true` en el body
- **AND** el backend ejecuta el comando con `--include-failed`
- **AND** al terminar el batch, el panel de resultados muestra `failed_recovered`, `failed_promoted_to_dead` y `failed_skipped_max_retries`

#### Scenario: Default behavior unchanged
- **WHEN** el operador NO marca el checkbox "Reintentar fallidos" (caso por defecto)
- **THEN** el batch se ejecuta como antes, sin reencolar transcripciones en error
- **AND** la UI no muestra las estadísticas de fallidos en el panel de resultados