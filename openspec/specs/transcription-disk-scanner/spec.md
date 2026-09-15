## Purpose

Define el comportamiento del escáner de disco que descubre grabaciones nuevas en el filesystem de cada `StorageProvider` con `transcription_enabled=true`, las encola para transcripción, aplica deduplicación cross-storage y gestiona reintentos de transcripciones fallidas o con archivos de tamaño insuficiente.

## Requirements

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

### Requirement: Scanner supports backlog recovery

El sistema SHALL soportar un parámetro `--days=N` para escanear también las carpetas de los N días anteriores al actual, `--from=DDMMYYYY --to=DDMMYYYY` para escanear las carpetas `dmY` explícitas de un rango, y `--all` para escanear todas las carpetas existentes bajo `base_path`. El alcance SHALL controlar únicamente el descubrimiento: la fase de envío SHALL seguir respetando el regulador de cola (`scan_max_dispatch_per_cycle`) sin importar el alcance.

#### Scenario: Recover yesterday recordings
- **WHEN** se ejecuta el scanner con `--days=1`
- **THEN** el sistema escanea la carpeta de hoy y la de ayer, procesando los `.mp4` sin transcripción de ambas

#### Scenario: Recover date range explicitly
- **WHEN** se ejecuta el scanner con `--from=01092026 --to=05092026`
- **THEN** el sistema escanea únicamente las carpetas `01092026`, `02092026`, `03092026`, `04092026`, `05092026` (hoy no incluida salvo que esté en el rango)

#### Scenario: Recover all historical backlog
- **WHEN** se ejecuta el scanner con `--all`
- **THEN** el sistema escanea recursivamente todas las carpetas bajo `base_path` que contengan `.mp4` sin transcripción, respetando `scan_batch` por ciclo

#### Scenario: El alcance no bypassa el regulador
- **WHEN** el escaneo con `--all` descubre 5,000 archivos nuevos en una corrida
- **THEN** el envío a cola de esa corrida sigue limitado por `scan_max_dispatch_per_cycle` y los `pending` restantes quedan para el regulador del cron

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

### Requirement: Archivo menor al mínimo se reintenta, no se descarta
El sistema SHALL, cuando un archivo de audio tiene un tamaño menor a `min_file_size_bytes` al momento del envío, **reencolar** la transcripción en vez de marcarla `dead`, siempre que el contador `retries` sea menor a `max_retries`.

#### Scenario: Stream congelado, archivo en 0 bytes
- **WHEN** el grabador crea un MP3 de 0 bytes y el stream de red se congela (el archivo no crece)
- **AND** el pipeline intenta transcribirlo y `filesize() < min_file_size_bytes`
- **AND** `retries < max_retries`
- **THEN** la `Transcription` se marca en `state=pending` con `requeue_after_at` futuro
- **AND** `retries` se incrementa en 1
- **AND** el tick la ignora hasta que venza `requeue_after_at`, y luego la reintenta

#### Scenario: Stream se descongela antes del reintento
- **WHEN** el archivo crece por encima de `min_file_size_bytes` antes del siguiente intento
- **THEN** el reintento transcribe el archivo normalmente (state → queued → done)

#### Scenario: Archivo genuinamente corrupto (0 bytes persistente)
- **WHEN** el archivo permanece menor a `min_file_size_bytes` tras `max_retries` intentos
- **THEN** la `Transcription` se marca `dead` con el mensaje de tamaño (comportamiento actual)

#### Scenario: Reintento no cuenta como aplazamiento de infraestructura
- **WHEN** el descarte por tamaño incrementa `retries`
- **THEN** el rebote por tmpfs sin espacio (`markRequeueable` de pre-flight) NO incrementa `retries` (comportamiento existente, no roto)

### Requirement: UI batch launcher runs the scanner process in a verifiable background task

El botón "Escanear storages" del modal del API Transcriptor SHALL lanzar el comando `transcription:scan-and-submit` en background de forma verificable. La ruta HTTP responde con `200 OK` y un `run_id` ANTES de que el proceso artisan arranque. El sistema SHALL entonces garantizar que, dentro de los 5 segundos posteriores al envío del `200`, el cache `transcription_batch:{runId}` transicione de `status: starting` a `status: running` o `status: queued` (señal de que el artisan arrancó y ejecutó su primera acción de cache). Si el wrapper de lanzamiento falla por sintaxis o por cualquier otro motivo que impida arrancar el artisan, el sistema SHALL escribir el cache con `status: error` y un mensaje accionable que apunte al log compartido, de modo que el modal frontend pueda mostrar el error al operador en vez de quedarse colgado en "Iniciando proceso en background..." durante el TTL completo (2h).

El contrato entre el trait de lanzamiento (`RunsBackgroundCommands::execBackground`) y los callers SHALL ser: el trait es el único responsable de agregar el `&` final y la redirección de salida; los callers SHALL construir comandos artisan puros sin redirección y sin `&` final. Si un caller histórico le pasa al trait un `$cmd` que ya termina en `&`, el trait SHALL detectar el patrón y eliminarlo antes de envolver, como defensa contra regresiones.

#### Scenario: Manual click launches the artisan and the cache transitions to running
- **WHEN** el operador hace clic en "Iniciar procesamiento" en el modal "Escanear storages" con al menos un storage habilitado
- **THEN** el frontend recibe un `200 OK` con un `run_id` válido
- **AND** dentro de los 5 segundos posteriores, el cache `transcription_batch:{runId}` muestra `status: running` o `status: queued`
- **AND** se crea el archivo de log `storage/logs/transcription-batch-{runId}.log` con al menos una línea de salida del comando artisan (PHP warnings, info del scanner, etc.)

#### Scenario: Launcher wrapper fails and the cache reports error with actionable message
- **WHEN** por cualquier motivo el wrapper bash que lanza el artisan no puede ejecutarlo (sintaxis inválida, binario PHP CLI no encontrado, permisos, etc.)
- **THEN** el cache `transcription_batch:{runId}` se actualiza con `status: error` y un mensaje que incluye la ruta al log compartido (`/tmp/kilo_artisan_bg.log`) filtrable por el `[logTag]` correspondiente
- **AND** el frontend muestra el mensaje de error en el panel de resultados del modal en lugar de quedarse en "Iniciando proceso en background..."

#### Scenario: Caller passes a $cmd with trailing ampersand and the trait strips it
- **WHEN** un caller pasa al trait `execBackground($cmd, $tag)` con `$cmd` que ya termina en ` &` (patrón histórico del controller de transcripción)
- **THEN** el trait elimina el `&` final antes de envolver el comando
- **AND** el artisan arranca normalmente sin producir `bash: -c: syntax error`

#### Scenario: Trait redirects per-run output to a dedicated log file when logFile is provided
- **WHEN** un caller pasa al trait un tercer argumento `?string $logFile` no nulo
- **THEN** toda la salida del artisan (stdout + stderr) se redirige tanto al log por-corrida como al log compartido `/tmp/kilo_artisan_bg.log` con los marcadores `[logTag] start` y `[logTag] end`

#### Scenario: No background process leaks when artisan exits immediately
- **WHEN** el artisan arranca pero falla antes de la primera escritura al cache (por ejemplo, `boot --no-dispatch` con todos los storages ya procesados, o un error fatal antes del primer `Cache::put`)
- **THEN** el subshell bash `setsid` cierra limpiamente
- **AND** el proceso artisan no queda zombie (el handler de SIGCHLD reaps el PID reparentado a init)

### Requirement: Frontend runBatch() polls the cache with the real run_id returned by the server

El frontend del modal "Escanear storages" SHALL garantizar que el polling de `/batch-status/{runId}` use el `run_id` REAL devuelto por el servidor en la respuesta HTTP del POST `/process-batch`. Si por motivos de latencia el `Promise.race` con el watchdog del frontend rechaza el fetch antes de que llegue la respuesta, el catch SHALL esperar a la promesa original del fetch para extraer el `run_id` real en vez de inventar uno sintético. Solo si el fetch original también falla (timeout de red total, error del servidor), el frontend SHALL mostrar un error de conexión accionable.

#### Scenario: Server is slow but responds eventually and frontend uses the real run_id
- **WHEN** el operador hace clic en "Iniciar procesamiento" y el servidor tarda entre 5s y 20s en responder
- **THEN** el frontend espera la respuesta real del fetch (más allá del watchdog inicial)
- **AND** el polling arranca con el `run_id` real devuelto por el servidor (`batch_xxx`)
- **AND** el modal muestra el progreso real del batch (cache `starting → running → queued`)

#### Scenario: Server never responds and frontend surfaces a connection error
- **WHEN** el operador hace clic en "Iniciar procesamiento" y el fetch original falla completamente (timeout de red total, 5xx no recuperable)
- **THEN** el frontend NO inventa un `run_id` sintético para pollear contra cache inexistente
- **AND** muestra un mensaje accionable de "sin respuesta del servidor" en el panel de resultados del modal
- **AND** el cache no queda "huérfano" con un `run_id` que no corresponde a un proceso real

### Requirement: Recarga con batch activo no fuerza la apertura del modal del escaneo de storages

Cuando el operador recarga `/ia/api-transcriptor` mientras hay un batch del transcriptor activo (cache key `transcription_batch:{runId}` con `status: starting|running|queued`), el módulo SHALL NO auto-abrir el modal de "Escanear storages". El polling del batch activo SHALL vivir en el indicador global del layout. El modal SHALL abrirse solo cuando el operador (a) hace click explícito en "Escanear storages" en el header del módulo, configurando un batch nuevo, o (b) navega a la URL `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}` desde el indicador global.

#### Scenario: Recarga con batch activo no fuerza el modal
- **WHEN** el operador recarga `/ia/api-transcriptor` mientras hay un batch activo
- **THEN** la página carga en el estado normal con el modal cerrado
- **AND** el indicador global del layout muestra el batch en curso
- **AND** el operador puede seguir interactuando con la página (cambiar de pestaña Storages/Trabajos/Configuración) sin nada le bloquee

#### Scenario: Click en el indicador global del batch abre el modal con el progreso
- **WHEN** el operador hace click en "Ver detalles" de una card del widget que corresponde a un batch del transcriptor
- **THEN** la URL resultante es `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}`
- **AND** el modal de "Escanear storages" se abre mostrando el progreso del batch activo

### Requirement: Scanner deriva `files.mime_type` desde la extensión, no hardcoded
`DiskScannerService::ensureFile()` y la rama `grouped_by_subfolder` que descubren archivos candidatos **SHALL** poblar la columna `files.mime_type` derivándola de la extensión del nombre del archivo, según el mapa definido en el spec `disk-scanner-file-mime-type`. **SHALL NOT** persistir `'video/mp4'` literal para todos los archivos descubiertos (comportamiento buggy previo a este cambio).

#### Scenario: Archivo `.mp4` se registra con `video/mp4`
- **WHEN** el scanner encuentra `base_path/dmY/grabacion.mp4`
- **THEN** el `File` registrado tiene `mime_type='video/mp4'`

#### Scenario: Archivo `.mp3` de radio se registra con `audio/mpeg`
- **WHEN** el scanner encuentra `base_path/dmY/lafm_140002.mp3` bajo un storage de radio (`grouped_by_subfolder` o `flat`)
- **THEN** el `File` registrado tiene `mime_type='audio/mpeg'`
- **AND** el spec `mis-avisos-media-kind` consume ese valor correctamente como `media_kind='radio'`

#### Scenario: Regresión: scanner nunca escribe `video/mp4` para audio
- **WHEN** existe un harness de regresión que procesa archivos `.mp3`, `.opus`, `.flac`, `.wav`, `.aac`, `.m4a` con el scanner
- **THEN** NINGUNA fila `files` resultante queda con `mime_type='video/mp4'` para esos archivos

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
