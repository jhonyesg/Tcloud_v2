## ADDED Requirements

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