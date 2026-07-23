## ADDED Requirements

### Requirement: Modal de archivos muestra botón contextual al estado
El sistema SHALL mostrar en la columna "Acción" del modal "Ver archivos" (modos `browse`, `today`, `yesterday`, `search`) un único botón cuyo texto/icono/color varía según el estado de la `Transcription` asociada al archivo.

#### Scenario: Archivo sin transcripción muestra botón Enviar
- **WHEN** el archivo no tiene `Transcription` asociada (`transcription_id` es `null`)
- **THEN** la columna Acción muestra un botón "Enviar" primario (color brand) que al hacer clic abre el modal de progreso (`openProgress(f)`) para iniciar el envío

#### Scenario: Archivo con transcripción en estado done muestra Ver transcripción
- **WHEN** el archivo tiene `Transcription` con `state = "done"`
- **THEN** la columna Acción muestra un botón/link "Ver transcripción" (color brand) que navega a `/ia/api-transcriptor/jobs/{transcription_id}` abriendo la vista detalle con SRT y metadata

#### Scenario: Archivo con transcripción en proceso muestra En proceso
- **WHEN** el archivo tiene `Transcription` con `state` en `["pending", "queued", "processing"]`
- **THEN** la columna Acción muestra un link "En proceso…" (color ámbar) que navega al job-detail para ver el progreso

#### Scenario: Archivo con transcripción en error muestra Ver error
- **WHEN** el archivo tiene `Transcription` con `state` en `["error", "dead"]`
- **THEN** la columna Acción muestra un link "Ver error" (color rojo) que navega al job-detail donde aparece el botón Reintentar

#### Scenario: Consistencia entre modos del modal
- **WHEN** el usuario abre el modal en modo `browse`, `today`, `yesterday` o `search`
- **THEN** el botón contextual se comporta idénticamente en todos los modos

---

### Requirement: Botón "Escanear storages" no bloquea la UI
El sistema SHALL responder el endpoint `POST /ia/api-transcriptor/process-batch` en menos de 1 segundo sin esperar a que el comando `transcription:scan-and-submit` termine. La respuesta incluye un `run_id` con el que el frontend puede hacer polling de progreso.

#### Scenario: Backend lanza proceso sin esperar al hijo
- **WHEN** el admin hace clic en "Iniciar procesamiento" en el modal "Escanear storages"
- **THEN** el endpoint `processBatch()` lanza el comando artisan en proceso separado usando `proc_open` con descriptores stdout/stderr redirigidos a `/dev/null`, llama `proc_close()` sin esperar, y retorna `200 {"run_id": "...", "batch": N, "message": "..."}` en <500ms

#### Scenario: Frontend entra a polling tras respuesta
- **WHEN** el frontend recibe la respuesta (exitosa o por timeout de 5s)
- **THEN** el estado `batchRunning` permanece `true` y `pollBatchStatus(runId)` se inicia, consultando `/ia/api-transcriptor/batch-status/{runId}` cada 2 segundos hasta que `status === "done"` o `"error"`

#### Scenario: Timeout del watchdog no aborta el lote
- **WHEN** la respuesta HTTP tarda más de 5 segundos
- **THEN** el frontend asume que el proceso fue iniciado, muestra "Escaneando..." y entra a polling de `batchStatus` igual que si la respuesta hubiera sido exitosa

---

### Requirement: Lote de archivos se procesa en paralelo por workers Redis
El sistema SHALL encolar `ConvertAndTranscribeJob` en Redis usando `dispatchWithPriority()` cuando el comando `transcription:scan-and-submit` o el endpoint `processBatch` procesan archivos. Hasta 10 queue workers supervisord consumen la cola en paralelo ejecutando ffmpeg + POST concurrentemente.

#### Scenario: scan-and-submit dispatcha en cola en lugar de ejecutar síncronamente
- **WHEN** el comando `transcription:scan-and-submit` encuentra N transcripciones pendientes sin `job_id`
- **THEN** llama `ConvertAndTranscribeJob::dispatchWithPriority(fileId, generateAlerts, priority)` para cada una y termina en <2 segundos sin esperar al envío

#### Scenario: Workers procesan en paralelo
- **WHEN** hay 50 jobs en la cola y 10 workers activos
- **THEN** hasta 10 jobs se procesan simultáneamente (10 procesos ffmpeg concurrentes)

#### Scenario: Worker muerto recupera el job
- **WHEN** un worker muere mientras procesa un job (OOM, kill manual)
- **THEN** tras el `timeout=120s` configurado en supervisor, Laravel marca el job como `failed`, Redis lo reencola y otro worker lo retoma; la `Transcription` con `state=pending` se reutiliza via `firstOrCreate`

#### Scenario: Transcription con job_id no se reenvía
- **WHEN** un job se dispatcha para un `file_id` cuya `Transcription` ya tiene `job_id` (caso race con schedule o lote manual duplicado)
- **THEN** el método `handle()` retorna inmediatamente sin reenviar el archivo, evitando duplicación en la API externa

---

### Requirement: ConvertAndTranscribeJob delega a TranscriptionSubmitService
El sistema SHALL hacer que el método `handle()` de `App\Jobs\ConvertAndTranscribeJob` delegue la ejecución del pipeline ffmpeg+POST a `App\Services\Ia\TranscriptionSubmitService::submit()` para evitar duplicación de código.

#### Scenario: Job ejecutándose vía cola usa el mismo pipeline
- **WHEN** un worker Redis consume un `ConvertAndTranscribeJob`
- **THEN** su `handle()` resuelve el `Transcription` por `file_id`, llama a `app(TranscriptionSubmitService::class)->submit($transcription)` y retorna el resultado

#### Scenario: Servicio síncrono sigue funcionando para endpoints UI
- **WHEN** el endpoint `POST /ia/api-transcriptor/transcribe/{fileId}` o `dispatchNow` ejecuta `TranscriptionSubmitService::submit()` directamente
- **THEN** el mismo código de pipeline corre, manteniendo comportamiento idéntico al del job en cola