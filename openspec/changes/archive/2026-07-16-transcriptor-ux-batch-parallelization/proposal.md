## Why

El módulo **API Transcriptor** tiene tres fricciones operativas que están bloqueando su uso bajo carga: (1) el modal "Ver archivos" muestra un botón "Enviar" en gris sin acción clara y un link implícito al job-detail que el usuario describe como confuso — la acción "Ver transcripción" no es descubrible; (2) el endpoint `processBatch()` (modal "Escanear storages") usa `exec()` sin detach real en `ApiTranscriptorController::execBackground`, lo que bloquea la request HTTP hasta que el proceso de scan-and-submit termina o se cierra el stream — la UI queda congelada sin feedback; (3) el pipeline de envío (`TranscriptionSubmitService::submit`) es estrictamente secuencial — un lote de 50 archivos tarda ~8 min porque cada uno espera secuencialmente al `ffmpeg` + POST. El servidor tiene 40 cores y Redis ya configurado (`QUEUE_CONNECTION=redis`), pero solo se usa 1 core por archivo. Esta reforma revierte parcialmente la decisión de 2026-07-16 (síncrono sin workers) reintroduciendo paralelización con cola Redis + supervisord, porque el volumen real y el caso de uso han cambiado.

## What Changes

- **UX — Columna "Acción" explícita**: en el modal "Ver archivos" (modos `browse`, `today`, `yesterday`, `search`), la celda del botón "Enviar" se reemplaza por una celda "Acción" con un botón cuyo texto/icono varía según estado: `Enviar` (transcribible sin `Transcription` o en estado `error`/`dead`) o `Ver transcripción` (cuando existe `Transcription` en cualquier estado, navega a `/ia/api-transcriptor/jobs/{transcription_id}`). El badge "Hecho"/"Pendiente" plano se mantiene en su celda separada. El botón inerte gris desaparece.
- **Liberar UI del modal "Escanear storages"**: `ApiTranscriptorController::execBackground` se reemplaza por un helper que usa `proc_open` con descriptores de archivo redirigidos a `/dev/null` (no al padre PHP) y sin `exec()` que espere al hijo. La request HTTP responde inmediatamente con `run_id`. Adicionalmente, el frontend (`runBatch()` en `index.blade.php`) gana un watchdog: si la respuesta HTTP tarda >5s, asume "started" y entra a polling de `batchStatus` igual, sin esperar al `await`.
- **Paralelización con cola Redis**: `TranscriptionSubmitService` se enriquece para poder ejecutarse dentro de un job Laravel (`ConvertAndTranscribeJob`, ya existente y `@deprecated`): se reactiva el job eliminando el `@deprecated`, se re-introduce `dispatchWithPriority` con prioridad manual alta, y se reactiva el supervisor (`/etc/supervisor/conf.d/tcloud-transcription-worker.conf`, 10 procs). El comando `transcription:scan-and-submit` cambia: en lugar de ejecutar `TranscriptionSubmitService::submit` síncronamente en un loop, hace `BulkDispatchTranscriptionJob::dispatch($fileIds)` para encolar N jobs a la vez en Redis. Los 10 workers procesan en paralelo. `ConvertAndTranscribeJob::handle` se refactoriza para delegar a `TranscriptionSubmitService` (sin duplicar el código ffmpeg+POST).

## Capabilities

### New Capabilities
- `transcriptor-batch-parallel-dispatch`: pipeline completo de envío en paralelo (Redis + workers supervisord) + liberación de UI del modal "Escanear storages" + columna "Acción" contextual en el modal "Ver archivos". Un solo spec cubre los tres problemas porque comparten el mismo flujo de envío.

### Modified Capabilities
- (ninguno — el spec `transcription-api-orchestrator` ya contiene el requirement "10 workers paralelos" (líneas 115-128); reactivar la infra no requiere modificar el spec, solo el código)

## Impact

- **Backend**:
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php`: `execBackground` (línea 660), `processBatch` (línea 589), `runBatch` Vue handler (línea 1689).
  - `app/app/Jobs/ConvertAndTranscribeJob.php`: quitar `@deprecated`, refactorizar `handle()` para delegar a `TranscriptionSubmitService`.
  - `app/app/Console/Commands/ScanAndSubmitCommand.php`: cambiar fase 2 (líneas 56-74) de loop síncrono a dispatch en cola.
  - `app/app/Services/Ia/TranscriptionSubmitService.php`: extraer lógica de pipeline a método público `runForTranscription(Transcription $t)` reutilizable por el job.
- **Infraestructura**: `/etc/supervisor/conf.d/tcloud-transcription-worker.conf` ya existe; solo se requiere `supervisorctl reread && update && start` para reactivar. 10 workers, queue `--queue=transcription-high,medium,low`.
- **Frontend**: `app/resources/views/ia/api-transcriptor/index.blade.php` — reemplazar la celda "Enviar" (líneas 345-351 y 393-399) por celda "Acción" con botón contextual; watchdog en `runBatch()` para no esperar la respuesta HTTP.
- **Migraciones**: no requiere.
- **Riesgo operacional**: reintroducir workers Redis añade 1.5 GB RAM y complejidad de supervisord. Mitigación: rollback = detener supervisor y revertir a `TranscriptionSubmitService` síncrono (el refactor deja esa puerta abierta).

## Non-goals

- No se modifica el comando `transcription:poll-results` (continúa su rol).
- No se cambia la vista `job-detail` ni la ruta `/jobs/{id}`.
- No se modifica el modelo `Transcription` ni su esquema.
- No se introduce un dashboard de workers individuales (monitoreo via `supervisorctl status` y logs).
- No se cambia el número de workers (10) — el usuario pidió "veinte en simultáneos" pero 10 es lo que la config actual tiene y nuestro presupuesto de RAM/ffmpeg tolera; aumentar es un cambio de infra posterior.