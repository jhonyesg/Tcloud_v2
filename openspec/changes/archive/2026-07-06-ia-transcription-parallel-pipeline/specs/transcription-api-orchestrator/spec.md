## MODIFIED Requirements

### Requirement: Scanner detecta archivos nuevos accesibles en storages habilitados
El sistema SHALL ejecutar el command `transcription:scan-new` cada 2-3 minutos vía Laravel scheduler, identificando archivos válidos sin transcripción previa en cada StorageProvider con `transcription_enabled = true`. El scanner SHALL solo procesar archivos cuyo `file_modified_at` sea de HOY (`>= today 00:00`), evitando procesar histórico automáticamente.

#### Scenario: Archivo de hoy elegible detectado
- **WHEN** existe un `File` con `file_modified_at >= today 00:00` y `file_modified_at < NOW() - 60s` y NO existe una `Transcription` con su `file_id`
- **THEN** el scanner encola un `ConvertAndTranscribeJob` con `generateAlerts=true` y prioridad automática (hoy + storage_priority)

#### Scenario: Archivo histórico NO se procesa automáticamente
- **WHEN** existe un `File` con `file_modified_at < today 00:00` sin transcripción previa
- **THEN** el scanner NO lo encola; el histórico solo se procesa manualmente vía lote, carpeta o día

#### Scenario: Archivo recién modificado se ignora
- **WHEN** un File tiene `file_modified_at >= NOW() - 60s` (aún se está escribiendo)
- **THEN** el scanner NO lo encola en este ciclo

#### Scenario: Archivo ya transcrito se ignora
- **WHEN** ya existe una `Transcription` (en cualquier estado) para un File
- **THEN** el scanner NO encola otro job para ese File

---

### Requirement: ConvertAndTranscribeJob convierte a Opus en RAM y envía a la API
El sistema SHALL convertir el archivo de entrada a Opus 64 kbps mono 16kHz usando ffmpeg local, escribiendo el archivo temporal en RAM (`/dev/shm` tmpfs con fallback a `sys_get_temp_dir()`), y enviarlo a la API con `callback_url`. El job SHALL aceptar un parámetro `generateAlerts` (bool) que controla si se disparan alertas de keywords al completar.

#### Scenario: Conversión en RAM exitosa
- **WHEN** el job recibe un File path válido y `/dev/shm` es escribible
- **THEN** se genera un archivo `.opus` en `/dev/shm/tcloud-transcription/` y se elimina al terminar (finally block)

#### Scenario: Fallback a disco si tmpfs no disponible
- **WHEN** `/dev/shm` no existe o no es escribible
- **THEN** el job usa `sys_get_temp_dir()` como fallback sin error

#### Scenario: Job con generateAlerts=false
- **WHEN** el job se crea con `generateAlerts=false`
- **THEN** la `Transcription` se persiste con `generate_alerts=false` y al completar (done), `TranscriptionProcessor` omite `KeywordMatcher::run()`

#### Scenario: ffmpeg falla
- **WHEN** el proceso ffmpeg retorna exit code != 0
- **THEN** el job lanza `RuntimeException`, se registra `error_message`, y el archivo temporal se borra en el finally block

---

### Requirement: Jobs se encolan con prioridad en Redis procesados por 10 workers paralelos
El sistema SHALL encolar `ConvertAndTranscribeJob` en Redis con una prioridad calculada como `storage_priority * 10 + (es_hoy ? 100 : 0) + (es_manual ? 5 : 0)`, y SHALL mantener 10 queue workers procesando la cola en paralelo via supervisor.

#### Scenario: Job de hoy con storage priority alta se procesa primero
- **WHEN** hay dos jobs en cola: uno de hoy (storage priority=10) y uno histórico (storage priority=10)
- **THEN** el job de hoy (priority=200) se procesa antes que el histórico (priority=105)

#### Scenario: 10 workers procesan en paralelo
- **WHEN** hay 50 jobs en cola y 10 workers activos
- **THEN** hasta 10 jobs se procesan simultáneamente (10 ffmpeg en paralelo, 25% de 40 cores)

#### Scenario: Worker muere y job vuelve a la cola
- **WHEN** un worker muere mientras procesa un job (OOM, kill)
- **THEN** tras el timeout (120s), Redis devuelve el job a la cola y otro worker lo retoma; `Transcription::firstOrCreate` reutiliza la fila existente con `state=pending`

---

### Requirement: Recuperación de jobs colgados (estado pending)
El sistema SHALL crear `Transcription` con `state=pending` (no `queued`) hasta que la API externa acepte el job y devuelva `job_id`. `scan-stale` SHALL recuperar `Transcription` con `state=pending` y `job_id IS NULL` con más de 5 minutos de antigüedad, reencolando el job.

#### Scenario: Transcription creada como pending
- **WHEN** el job crea la Transcription antes del POST a la API
- **THEN** se persiste con `state=pending` y `job_id=null`

#### Scenario: Transcription pasa a queued tras aceptación de API
- **WHEN** la API externa responde 200 con `job_id`
- **THEN** la Transcription se actualiza a `state=queued` con el `job_id` recibido

#### Scenario: scan-stale recupera pending colgado
- **WHEN** scan-stale encuentra una Transcription con `state=pending`, `job_id=null`, `created_at < NOW() - 5 min`
- **THEN** dispatchea un nuevo `ConvertAndTranscribeJob` para ese `file_id`; el job reutiliza la Transcription existente via `firstOrCreate`

---

### Requirement: Procesamiento por lote en background con alertas opcionales
El sistema SHALL permitir al admin iniciar un lote global de hasta 200 archivos que se ejecuta en background (proceso separado via `nohup`), distribuyendo el lote entre storages habilitados según prioridad. El admin SHALL poder elegir si el lote genera alertas (checkbox, default OFF).

#### Scenario: Lote iniciado en background
- **WHEN** el admin selecciona batch=50 y hace clic en "Iniciar procesamiento"
- **THEN** el sistema lanza `transcription:process-batch --batch=50 --run-id=xxx` en background, devuelve inmediatamente un `run_id`, y el frontend hace polling cada 2s del progreso

#### Scenario: Lote con alertas deshabilitadas
- **WHEN** el admin inicia un lote con "Generar alertas" desmarcado
- **THEN** todos los jobs del lote se crean con `generateAlerts=false` y al completar no se disparan alertas

#### Scenario: Lote con alertas habilitadas
- **WHEN** el admin marca "Generar alertas" e inicia el lote
- **THEN** los jobs se crean con `generateAlerts=true` y al completar se disparan alertas normalmente

#### Scenario: Lote distribuido por prioridad
- **WHEN** hay 2 storages habilitados: Caracol (priority=10, 100 candidatos) y Radio (priority=0, 50 candidatos) y batch=50
- **THEN** el lote asigna más archivos a Caracol que a Radio, proporcional al peso `candidatos * (1 + priority/10)`

#### Scenario: Cerrar/recargar no detiene el lote
- **WHEN** el admin cierra el modal o recarga la página mientras el lote corre
- **THEN** el lote continúa en background; al reabrir el modal, el polling retoma el progreso si el `run_id` sigue en cache

---

### Requirement: Procesamiento manual por carpeta o día
El sistema SHALL permitir al admin procesar todos los archivos de una carpeta específica o de un día (HOY/AYER) desde el navegador de archivos, encolando jobs con prioridad manual y alertas opcionales.

#### Scenario: Procesar carpeta actual
- **WHEN** el admin está navegando una carpeta y hace clic en "Procesar carpeta"
- **THEN** el sistema encola `ConvertAndTranscribeJob` para cada archivo sin transcripción de la carpeta actual (parent_id), con `generateAlerts` según checkbox

#### Scenario: Procesar día
- **WHEN** el admin está en modo HOY o AYER y hace clic en "Procesar día"
- **THEN** el sistema encola jobs para todos los archivos visibles sin transcripción, con `generateAlerts` según checkbox

#### Scenario: Confirmación antes de encolar
- **WHEN** el admin hace clic en "Procesar carpeta" o "Procesar día"
- **THEN** se muestra confirmación con el número de archivos a encolar antes de proceder