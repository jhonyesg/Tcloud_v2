## 1. Migración y config

- [x] 1.1 Crear migración `add_node_id_to_transcriptions_table` (nullable string `node_id`)
- [x] 1.2 Añadir `node_id` al fillable del modelo `Transcription`
- [x] 1.3 Actualizar `config/transcriptor.php`: eliminar `callback_host` y `webhook_token`, añadir `poll_interval_seconds` (default 30), `scan_days_back` (default 0)
- [x] 1.4 Actualizar `.env.example` con las nuevas vars y quitar las obsoletas

## 2. Servicio de escaneo de disco

- [x] 2.1 Crear `app/app/Services/Ia/DiskScannerService.php` con método `scanStorage(StorageProvider $storage, int $daysBack): array`
- [x] 2.2 Implementar `scandir(base_path . '/' . date('dmY'))` con filtro `filemtime < now - scan_min_age_seconds`
- [x] 2.3 Implementar `--days=N`: iterar fechas desde hoy hasta hoy-N, escanear cada carpeta `dmY`
- [x] 2.4 Implementar `--all`: escaneo recursivo de todas las carpetas bajo `base_path` con `.mp4`
- [x] 2.5 Para cada `.mp4`: crear `File` (path='dmY/name') si no existe, con `file_modified_at` real
- [x] 2.6 Para cada `File` sin `Transcription`: crear `Transcription` en `state=pending` sin `job_id`
- [x] 2.7 Respetar `scan_batch` (limit) y ordenar por `file_modified_at` desc

## 3. Servicio de envío síncrono

- [x] 3.1 Crear `app/app/Services/Ia/TranscriptionSubmitService.php` con método `submit(Transcription $t): void`
- [x] 3.2 Mover la lógica de `ConvertAndTranscribeJob::handle` (ffmpeg opus + POST) al nuevo servicio
- [x] 3.3 Enviar `POST /v1/transcribe` con `lang_fix=async`, `language=es`, SIN `callback_url`
- [x] 3.4 Persistir `job_id`, `node_url` (=base_url del cliente), `node_id` (de la respuesta o de `/api/info`) en la `Transcription`
- [x] 3.5 Marcar `Transcription` en `state=queued`
- [x] 3.6 Manejar errores (archivo no legible, ffmpeg falla, API cae): marcar `state=error` con mensaje

## 4. Servicio de polling de resultados

- [x] 4.1 Crear `app/app/Services/Ia/TranscriptionPollingService.php` con método `pollAll(): array`
- [x] 4.2 Query: `Transcription` en `queued`/`processing` con `job_id`, limit 100
- [x] 4.3 Para cada una: `GET {node_url}/v1/jobs/{job_id}` vía `TranscriptorApiClient::getJob`
- [x] 4.4 Si `state=done`: descargar SRT (`getSrt` con `srt_url` absoluta o prefijar `node_url`)
- [x] 4.5 Invocar `TranscriptionProcessor::processDone()` para parsear, segments, correcciones, keywords
- [x] 4.6 Si `state=error`/`dead`/`cancelled`: invocar `TranscriptionProcessor::markError()`
- [x] 4.7 Si sigue `queued`/`processing`: no cambiar estado (reintenta próximo ciclo)
- [x] 4.8 Tolerar fallos de conectividad: log + no cambiar estado (sin marcar error)

## 5. Comandos artisan

- [x] 5.1 Crear `app/app/Console/Commands/ScanAndSubmitCommand.php` (signature `transcription:scan-and-submit {--days=0} {--all}`)
- [x] 5.2 En `handle`: usar `DiskScannerService` para crear pendientes, luego `TranscriptionSubmitService` para enviar los `pending` sin `job_id`
- [x] 5.3 Crear `app/app/Console/Commands/PollResultsCommand.php` (signature `transcription:poll-results`)
- [x] 5.4 En `handle`: usar `TranscriptionPollingService::pollAll()`; además reenviar `pending` sin `job_id` > `stale_after_minutes`
- [x] 5.5 Logs informativos por comando (dispatched, polled, done, errors)

## 6. Schedule y eliminación de legacy

- [x] 6.1 En `routes/console.php`: reemplazar `transcription:scan-new` (c/2min) por `transcription:scan-and-submit` (c/2min, `--days=0`)
- [x] 6.2 En `routes/console.php`: reemplazar `transcription:scan-stale` (c/5min) por `transcription:poll-results` (cada `poll_interval_seconds`, con `withoutOverlapping`)
- [x] 6.3 Eliminar ruta `POST /webhooks/transcription` de `routes/web.php`
- [x] 6.4 Eliminar `app/app/Http/Controllers/Ia/TranscriptionCallbackController.php`
- [x] 6.5 Marcar como deprecated (o eliminar) `ScanNewRecordingsCommand`, `ScanStaleJobsCommand`, `ProcessBatchCommand`
- [x] 6.6 Marcar como deprecated `ConvertAndTranscribeJob` (o eliminar si ningún endpoint manual lo usa)
- [x] 6.7 Actualizar `ApiTranscriptorController` para que los endpoints manuales (`transcribeFile`, `reprocess`, `dispatchNow`, `retry`) usen `TranscriptionSubmitService` en vez de `ConvertAndTranscribeJob` por Reflection

## 7. Actualización del cliente API

- [x] 7.1 En `TranscriptorApiClient::submit`: eliminar el parámetro `callbackUrl` y el campo `callback_url` del POST
- [x] 7.2 Añadir método `TranscriptorApiClient::getInfo(): array` (`GET /api/info`) para catalogar el nodo
- [x] 7.3 Ajustar `getSrt` para aceptar `srt_url` absoluta (ya lo hace vía `node_url`, verificar)

## 8. Validación y pruebas

- [x] 8.1 Verificar que el schedule corre sin errores: `artisan schedule:run` manual
- [x] 8.2 Ejecutar `transcription:scan-and-submit` manual y confirmar que crea pendientes y envía
- [x] 8.3 Ejecutar `transcription:poll-results` manual y confirmar que recupera jobs `queued` existentes a `done`
- [x] 8.4 Verificar que una transcripción nueva completa el ciclo (pending → queued → done) en < 3 min
- [x] 8.5 Ejecutar `transcription:scan-and-submit --days=1` y verificar recuperación de ayer
- [x] 8.6 Confirmar que los endpoints manuales de la UI siguen funcionando
- [x] 8.7 Detener los workers Redis de transcripción y confirmar que el flujo no se rompe

## 9. Recuperación de backlog

- [x] 9.1 Ejecutar `transcription:scan-and-submit --days=7` en background (nohup) para recuperar la última semana
- [x] 9.2 Monitorear progreso vía logs y la UI de `/ia/api-transcriptor`
- [x] 9.3 Evaluar ejecutar `--all` para los 4196 archivos históricos restantes (en lotes, no de golpe)