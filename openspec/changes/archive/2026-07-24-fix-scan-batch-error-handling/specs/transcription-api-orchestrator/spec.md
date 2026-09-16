## ADDED Requirements

### Requirement: Batch scan is resilient to per-storage failures
El sistema SHALL, al ejecutar `transcription:scan-and-submit` (manual desde UI o automático desde `transcription:tick`), aislar las excepciones de un storage individual de modo que un fallo en un solo `StorageProvider` no impida el procesamiento del resto. Cuando un storage falle, el comando SHALL registrar el error en el log, acumularlo en `per_storage_errors` de la cache `transcription_batch:<runId>`, continuar con los siguientes storages y al finalizar escribir `status='partial'` (al menos un storage tuvo éxito) o `status='error'` (todos fallaron).

#### Scenario: One storage fails mid-scan
- **WHEN** el comando procesa N storages y uno de ellos (ej. storage 133) lanza una excepción durante `DiskScannerService::scanStorage()`
- **THEN** el comando registra el error con `Log::error` incluyendo `storage_id` y mensaje
- **AND** continúa iterando los storages restantes
- **AND** al finalizar, la cache contiene `status='partial'` con `per_storage_errors=[{storage_id, storage_name, message}]`
- **AND** el UI muestra el mensaje al operador

#### Scenario: All storages fail
- **WHEN** todos los storages lanzan excepción durante el scan
- **THEN** el comando escribe `status='error'` a la cache con `total_candidates=0`, `errors=N` y `message` legible
- **AND** el UI polling termina con un estado definitivo y muestra el error

### Requirement: Batch scan guarantees terminal cache state on fatal error
El sistema SHALL envolver el cuerpo de `ScanAndSubmitCommand::handle()` en un try/catch global que, ante cualquier excepción no controlada, escriba `status='error'` a la cache `transcription_batch:<runId>` con el mensaje original de la excepción, de modo que el polling del frontend siempre reciba un estado terminal (`done`, `error` o `not_found`) y nunca quede en estado `starting`/`running` indefinidamente.

#### Scenario: Unhandled exception during scan-and-submit
- **WHEN** una excepción no controlada ocurre durante la ejecución del comando (ej. SQLSTATE 42703 por columna faltante)
- **THEN** el catch global escribe a la cache: `status='error'`, `message=<excepción>`, `errors=1`, `started_at`/`finished_at`/`updated_at` en ISO 8601
- **AND** el comando retorna `Command::FAILURE`
- **AND** el frontend polling se detiene y muestra el error en el modal

### Requirement: UI surfaces batch scan error messages from backend
El sistema SHALL mostrar en el modal "Escanear storages" el campo `message` devuelto por `/ia/api-transcriptor/batch-status/<runId>` cuando `status` es `error` o `partial`, en lugar de un `alert()` genérico de "Error de conexión". Si `per_storage_errors` está presente, SHALL listar cada storage fallido con su mensaje.

#### Scenario: Batch ends with error
- **WHEN** el polling recibe `status='error'` con `message="..."`
- **THEN** el panel de resultados del modal muestra el mensaje en texto rojo/visible
- **AND** si `per_storage_errors` existe, lista cada storage con su `storage_name` y `message`
- **AND** no se muestra ningún `alert()` adicional

#### Scenario: Batch ends partially successful
- **WHEN** el polling recibe `status='partial'` con `per_storage_errors` no vacío
- **THEN** el panel muestra resultados normales (`processed`, `files`, `storages`) más una sección colapsable con los storages fallidos

### Requirement: Polling terminates on terminal states
El sistema SHALL detener el polling del frontend y mostrar el panel de resultados cuando el estado de cache sea CUALQUIERA de los estados terminales: `done`, `queued`, `error`, `partial` o `not_found`. El estado `queued` representa la finalización exitosa del batch-and-submit (los jobs fueron encolados a Redis y los workers supervisord los procesarán en paralelo).

#### Scenario: Batch ends with status 'queued' (success)
- **WHEN** el polling recibe `status='queued'` con `total_to_process`, `per_storage_errors` y demás campos
- **THEN** el polling se detiene
- **AND** `batchResult` se setea con la respuesta completa
- **AND** el modal muestra los counters (Procesados/Errores/Candidatos) y el panel de message (vacío si no hubo errores)