# Change: Reintento automático de transcripciones fallidas con archivo accesible

## Why

Actualmente 1018 transcripciones están en estado terminal de fallo (`error`: 885, `dead`: 133) y no se reintentan automáticamente. Solo el operador puede reprocesarlas una por una desde la UI (botón "Reprocesar" por fila). Esto es lento y deja archivos huérfanos indefinidamente.

Casos típicos:
- Transcriptor externo (`asr-parakeet`) saturado o caído → `error: HTTPConnectionPool Max retries exceeded`
- Proceso de transcripción murió a mitad del camino → el operador nunca se entera
- Archivo es válido pero el worker de Redis crasheó antes de marcar `done`

El scanner actual (`DiskScannerService::scanStorage()`) solo busca archivos sin `Transcription` row (`whereNotExists`). Archivos con `Transcription` en `error`/`dead` quedan invisibles para el batch manual y el tick automático.

## What Changes

- **Flag `--include-failed` en `transcription:scan-and-submit`**: cuando se pasa, además de los archivos sin transcripción, recoge las `Transcription` con `state='error'` cuyo archivo en disco sigue accesible, con `retries < max_retries` (default 3).
- **Lógica de reintento**: para cada `Transcription` elegible, resetear `state='pending'`, `error_message=null`, `job_id=null`, `node_url=null`, `node_id=null`, e incrementar `retries`. Mantiene el `file_id` (no borra ni crea nueva fila).
- **Chequeo de accesibilidad**: antes de encolar, verificar `is_file($path) && is_readable($path)` (misma lógica que `TranscriptionSubmitService::submit()`). Si no es accesible, dejar la fila en `state='dead'` con mensaje claro y contar en estadísticas.
- **Promoción a `dead` después de N reintentos**: cuando `retries >= max_retries` y vuelve a fallar, mover la fila a `state='dead'` con `error_message` que mencione el historial de retries. Queda fuera del scope automático para siempre.
- **Checkbox UI "Reintentar fallidos" en el modal "Escanear storages"**: separado del "Generar alertas". Default OFF (cambio opt-in). El frontend envía `include_failed=true` en el body del POST.
- **Estadísticas del cache**: extender el cache `transcription_batch:<runId>` con campos `failed_recovered` (cuántos error se reencolaron), `failed_skipped_unreadable` (cuántos no se reencolaron por archivo no accesible), `failed_promoted_to_dead` (cuántos pasaron a dead en este ciclo).

## Impact

- **Specs AFFECTED**: `transcription-disk-scanner` (nuevos requirements para retry de fallidos).
- **Code affected**:
  - `app/app/Console/Commands/ScanAndSubmitCommand.php` — nueva opción `--include-failed`, nueva fase de recolección de fallidos, escritura de estadísticas extendidas.
  - `app/app/Services/Ia/DiskScannerService.php` — opcional: nuevo método `collectFailedCandidates(int $storageId, int $maxRetries): Collection<Transcription>` para mantener separación de responsabilidades.
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `processBatch()` acepta `include_failed` en el body y lo pasa al comando.
  - `app/resources/views/ia/api-transcriptor/index.blade.php` — nuevo checkbox en el modal.
- **Sin cambios en BD**: el campo `retries` ya existe en la tabla `transcriptions`.
- **Sin cambios en el modelo Transcription**: solo se usa `state`, `retries`, `error_message` que ya están en `$fillable`.

## Behavioral rules

1. **Solo `state='error'`**: NO se incluye `state='dead'` en el reintento automático (un archivo muerto se considera no válido por diseño del operador o por多次 fallo consecutivo). El operador puede reprocesar manualmente desde la UI si quiere.
2. **Accesibilidad primero**: si el archivo no es accesible, NO se reintenta y se cuenta en `failed_skipped_unreadable`. Esto cubre el caso "archivo borrado del disco" sin saturar Redis con jobs que van a fallar otra vez.
3. **Límite de retries**: 3 reintentos automáticos. Al cuarto fallo consecutivo, la fila pasa a `state='dead'` con mensaje "Max retries alcanzado (3/3). Requiere acción manual." El operador puede reprocesar manualmente después de investigar.
4. **NO se duplican transcripciones**: como NO se borra la fila, se preserva el `id` y el historial. El campo `error_message` se sobreescribe con el nuevo error (perdiendo el histórico, pero aceptable porque el campo ya documenta el último fallo).
5. **Idempotencia con el tick automático**: si el tick encola el mismo file que el batch manual (improbable porque el tick solo mira pending del día actual y los retried vuelven a pending), el `ConvertAndTranscribeJob` ya tiene guard de idempotencia (`if (!empty($transcription->job_id)) return;`).
6. **Reseteo parcial**: NO se borra `started_at`, `finished_at`, `generate_alerts`, `language`, `original_name`. Solo se limpian los campos de estado de envío y se incrementa `retries`.

## Non-goals

- No cambiar el comportamiento del botón "Reprocesar" uno-a-uno (sigue funcionando como hoy).
- No incluir archivos en `state='dead'` en el reintento automático (alcance acotado).
- No agregar una vista de historial de retries (sería nice-to-have pero fuera de scope).
- No cambiar el límite de retries por storage (sería lógico pero se puede agregar después si hace falta).
- No ejecutar retries en el tick automático (solo en batch manual con flag explícito, para control del operador).