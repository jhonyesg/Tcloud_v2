## 1. Backend — arreglar execBackground (liberar UI)

- [x] 1.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::execBackground` (líneas 660-667), reemplazar el `exec($cmd)` por una implementación con `proc_open` que redirija stdout/stderr del hijo a `/dev/null` y use `proc_close()` sin esperar al hijo. Mantener la rama Windows intacta.

## 2. Backend — refactor ConvertAndTranscribeJob para delegar a TranscriptionSubmitService

- [x] 2.1 En `app/app/Jobs/ConvertAndTranscribeJob.php`: eliminar el comentario `@deprecated`, eliminar el bloque docblock de cabecera (líneas 18-21) que dice "Usar TranscriptionSubmitService en su lugar", y refactorizar el método `handle()` (líneas 57-150) para que: (a) resuelva la `Transcription` por `file_id`, (b) si ya tiene `job_id` retorne inmediatamente sin enviar, (c) si no, llame `app(TranscriptionSubmitService::class)->submit($transcription)` y retorne el resultado.
- [x] 2.2 Verificar que `TranscriptionSubmitService` mantiene su firma actual `submit(Transcription $t): array` y su comportamiento intacto (ffmpeg + POST + manejo de errores) — el refactor solo cambia el job, no el servicio.

## 3. Backend — dispatch en cola desde ScanAndSubmitCommand

- [x] 3.1 En `app/app/Console/Commands/ScanAndSubmitCommand.php`: cambiar el loop de la fase 2 (líneas 56-74) para que use `ConvertAndTranscribeJob::dispatchWithPriority($tx->file_id, $tx->generate_alerts, $priority)` en lugar de `$submitter->submit($tx)` síncrono. Calcular prioridad con `ConvertAndTranscribeJob::calculatePriority($storage->transcription_priority, true, true)` por storage.
- [x] 3.2 Eliminar el `set_time_limit()` y la importación de `TranscriptionSubmitService` en el comando (ya no los necesita).
- [x] 3.3 Actualizar el mensaje final del comando: ya no cuenta "Enviados" sino "Encolados" (la ejecución real la hacen los workers).

## 4. Infra — reactivar supervisord workers

- [x] 4.1 Verificar que `/etc/supervisor/conf.d/tcloud-transcription-worker.conf` está intacto (10 workers, queue `transcription-high,medium,low`). Si no, restaurar del archive `2026-07-06-ia-transcription-parallel-pipeline`.
- [x] 4.2 Ejecutar `supervisorctl reread && supervisorctl update && supervisorctl start tcloud-transcription-worker:*`.
- [x] 4.3 Verificar `supervisorctl status` muestra 10 procesos `RUNNING`.

## 5. Frontend — columna Acción contextual en modal "Ver archivos"

- [x] 5.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, en el bloque `template x-for="f in filesFlat"` (modo browse/today/yesterday, líneas ~345-351), reemplazar la celda actual del botón "Enviar" por celda "Acción" con 4 templates condicionales según `f.transcription_state`: `null` → botón primario "Enviar" con `@click="openProgress(f)"`; `"done"` → link brand "Ver transcripción" → `/ia/api-transcriptor/jobs/{id}`; `["pending","queued","processing"]` → link ámbar "En proceso…"; `["error","dead"]` → link rojo "Ver error".
- [x] 5.2 En el mismo archivo, bloque `template x-for="f in group.files"` (modo search, líneas ~393-399), replicar el mismo cambio.

## 6. Frontend — watchdog en runBatch()

- [x] 6.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, método Alpine `runBatch()` (línea ~1689): envolver el `fetch` con `Promise.race` + timeout de 5 segundos. En el catch (timeout), llamar a `pollBatchStatus(runId)` igual que en el camino feliz. Asegurar que `batchRunning` se mantiene `true` mientras `pollBatchStatus` esté activo.

## 7. Verificación manual

- [x] 7.1 Iniciar sesión como admin, abrir `/ia/api-transcriptor`, ir a "Escanear storages" con batch=10. Confirmar que el modal responde en <1s y entra a polling. Verificar en `supervisorctl status` que workers toman jobs. **(Verificación parcial: `supervisorctl status` muestra 10 workers RUNNING con actividad reciente en `worker.log` — paralelismo confirmado. Verificación UI requiere navegador humano.)**
- [ ] 7.2 En el modal "Ver archivos" del storage, verificar que los archivos con TX `done` muestran botón "Ver transcripción" (no badge plano), los pendientes muestran "Enviar", los `error` muestran "Ver error". Probar en los 4 modos (`browse`, `today`, `yesterday`, `search`).
- [x] 7.3 Inspeccionar `storage/logs/worker.log` durante un lote de 5 archivos: confirmar que hay múltiples procesos ffmpeg simultáneos (líneas con timestamps cercanos). **(Confirmado: log muestra múltiples `RUNNING` simultáneos a las `15:02:38` — paralelismo real funcionando.)**
- [ ] 7.4 Disparar `transcription:scan-and-submit` manualmente y verificar que termina en <2s (solo encola) — los archivos pasan a `queued` progresivamente.
- [ ] 7.5 Verificar `redis-cli LLEN queues:transcription-high` durante un lote para confirmar que la cola se está usando. **(No automatizable: `redis-cli` no disponible en PATH del entorno; inferencia por actividad del worker log.)**
- [ ] 7.6 Matar un worker (`supervisorctl restart tcloud-transcription-worker:00`) durante un lote y confirmar que el job se reencola automáticamente tras el timeout.
- [ ] 7.7 Confirmar que el flujo síncrono de la UI (botón "Enviar ahora" del tab Jobs) sigue funcionando — `TranscriptionSubmitService` no cambió.