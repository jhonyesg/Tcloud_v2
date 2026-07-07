## 1. Migración y modelo

- [x] 1.1 **[migración]** Crear `2026_07_xx_add_generate_alerts_to_transcriptions` — añadir `generate_alerts boolean default true` a `transcriptions` después de `state`
- [x] 1.2 Actualizar `App\Models\Transcription`: añadir `pending` a constantes `STATE_PENDING`, añadir `generate_alerts` a `$fillable` y `$casts` (boolean), actualizar scope `pending()` para incluir `pending` además de `queued|processing`
- [x] 1.3 Correr migración: `php artisan migrate` y verificar columna en PG

## 2. Job y servicios backend

- [x] 2.1 Actualizar `ConvertAndTranscribeJob`: aceptar `bool $generateAlerts = true` en constructor, pasar `generate_alerts` al `firstOrCreate` de Transcription, crear con `state=pending` (no `queued`), seguir usando tmpfs (`/dev/shm`) para Opus, volver a usar `dispatch()` (no reflection)
- [x] 2.2 Actualizar `ConvertAndTranscribeJob`: al recibir `job_id` de la API, actualizar `state=queued` (pasar de `pending` a `queued`); si la API falla, actualizar `state=error`
- [x] 2.3 Actualizar `AudioConverter`: confirmar que tmpfs (`/dev/shm`) está en uso para Opus y progreso (ya parcialmente hecho)
- [x] 2.4 Actualizar `TranscriptionProcessor::processDone()`: antes de `KeywordMatcher::run()`, verificar `if ($transcription->generate_alerts)` — si false, omitir matching
- [x] 2.5 Verificar que `TranscriptionCallbackController` persiste `generate_alerts` correctamente (viene del job, ya está en la fila)

## 3. Prioridad en cola Redis

- [x] 3.1 Configurar cola Redis con prioridad: usar `$job->onQueue('transcription-' . $priorityBucket)` donde bucket = `high` (priority >= 100), `medium` (50-99), `low` (<50); o usar un solo queue con ordenamiento por priority si Laravel lo soporta via `Redis::zadd`
- [x] 3.2 Añadir método helper `calculatePriority(int $storagePriority, bool $isToday, bool $isManual): int` en `ConvertAndTranscribeJob` o un service
- [x] 3.3 Verificar que `dispatch()` del job incluye la prioridad calculada

## 4. Commands

- [x] 4.1 Actualizar `ScanNewRecordingsCommand`: añadir filtro `WHERE file_modified_at >= today 00:00` (solo HOY), mantener `whereNotExists` transcripción, usar `dispatch()` con `generateAlerts=true`, pasar prioridad (auto + hoy)
- [x] 4.2 Actualizar `ScanStaleJobsCommand`: añadir recuperación de `Transcription` con `state=pending AND job_id IS NULL AND created_at < NOW() - 5 min` → `dispatch()` nuevo job para ese `file_id`
- [x] 4.3 Actualizar `ProcessBatchCommand`: usar `dispatch()` (no ejecución síncrona por reflection) con `generateAlerts` según parámetro `--alerts=0|1`, pasar prioridad (manual)
- [x] 4.4 Verificar que `scan-new` y `scan-stale` siguen registrados en `routes/console.php` con `withoutOverlapping()`

## 5. Controladores y rutas

- [x] 5.1 Añadir método `processFolder(Request, int $storageId)` en `ApiTranscriptorController`: recibe `parent_id` y `generate_alerts` (bool), encola jobs para archivos sin transcripción de esa carpeta
- [x] 5.2 Añadir método `processDay(Request, int $storageId)` en `ApiTranscriptorController`: recibe `mode` (today|yesterday) y `generate_alerts`, encola jobs para archivos visibles
- [x] 5.3 Actualizar `processBatch` en `ApiTranscriptorController`: pasar `--alerts=0|1` al comando artisan según request, usar `dispatch()` implícito via el command
- [x] 5.4 Registrar rutas: `POST /api-transcriptor/storages/{id}/process-folder`, `POST /api-transcriptor/storages/{id}/process-day`
- [x] 5.5 Añadir endpoint `POST /api-transcriptor/jobs/{id}/reprocess` que acepte `generate_alerts` bool (ya existe, verificar que pasa el flag al job)

## 6. Supervisor y workers

- [x] 6.1 Verificar/installar supervisor: `apt install supervisor` (o verificar si ya existe)
- [x] 6.2 Crear config `/etc/supervisor/conf.d/tcloud-transcription-worker.conf` con 10 procs, `php artisan queue:work redis --sleep=1 --tries=1 --timeout=120 --max-jobs=100`
- [x] 6.3 `supervisorctl reread && supervisorctl update && supervisorctl start tcloud-transcription-worker:*`
- [x] 6.4 Verificar que 10 workers están activos: `supervisorctl status`
- [x] 6.5 Enviar un job de prueba y verificar que un worker lo procesa: ver `storage/logs/worker.log`

## 7. Frontend — lote con alertas opcionales

- [x] 7.1 En el modal de lote (`index.blade.php`), añadir checkbox "Generar alertas" (default desmarcado) con tooltip "Marca si quieres que los archivos procesados disparen alertas de keywords"
- [x] 7.2 Pasar `generate_alerts` en el `JSON.stringify` del `runBatch()` method
- [x] 7.3 En el modal de progreso del lote, mostrar si las alertas están activadas o no

## 8. Frontend — botones carpeta y día

- [x] 8.1 En el navegador de archivos (`showFiles` modal), añadir botón "Procesar carpeta" en la toolbar (visible en modo browse), que llama a `processFolder(currentParent)`
- [x] 8.2 Añadir botón "Procesar día" (visible en modo today/yesterday), que llama a `processDay(filesMode)`
- [x] 8.3 Ambos botones abren un mini-modal de confirmación: "Se encolarán N archivos. ¿Generar alertas? [checkbox]" + botón "Encolar"
- [x] 8.4 Métodos Alpine `processFolder(parentId)` y `processDay(mode)`: POST al endpoint correspondiente, mostrar confirmación con número de jobs encolados
- [x] 8.5 Tras encolar, refrescar la lista de archivos (loadFiles) para mostrar los nuevos como "Pendiente" (has_transcription=true con state pending)

## 9. Cron de limpieza tmpfs

- [x] 9.1 Añadir entrada en `routes/console.php`: `Schedule::command('transcription:cleanup-tmpfs')->hourly()` que borre archivos `.opus` y `.json` en `/dev/shm/tcloud-transcription*` con más de 1 hora de antigüedad
- [x] 9.2 Crear `CleanupTmpfsCommand` (`transcription:cleanup-tmpfs`) que haga `find /dev/shm/tcloud-transcription* -type f -mmin +60 -delete`

## 10. Verificación

- [x] 10.1 Smoke test: habilitar storage con archivos de HOY, verificar que scan-new solo procesa los de hoy (no histórico)
- [x] 10.2 Smoke test: iniciar lote de 50 archivos, verificar que 10 workers procesan en paralelo (ver logs de worker, múltiples ffmpeg simultáneos)
- [x] 10.3 Smoke test: verificar que jobs `pending` aparecen en la tabla de pendientes y pasan a `queued` al recibir job_id
- [x] 10.4 Smoke test: matar un worker manualmente (`kill`), verificar que supervisor lo reinicia y el job vuelve a la cola
- [x] 10.5 Smoke test: crear un job `pending` sin job_id manualmente en DB, esperar 5 min, verificar que scan-stale lo recupera
- [x] 10.6 Smoke test: procesar carpeta desde el navegador, verificar que se encolan los archivos correctos
- [x] 10.7 Smoke test: procesar día (AYER), verificar que se encolan solo los archivos de ayer
- [x] 10.8 Smoke test: lote con "Generar alertas" desmarcado, verificar que `transcriptions.generate_alerts=false` y no se envían emails
- [x] 10.9 Smoke test: lote con "Generar alertas" marcado, verificar que se disparan alertas normalmente
- [x] 10.10 Verificar que cerrar el modal del lote no detiene el procesamiento (recargar página, lote sigue corriendo)
- [x] 10.11 Verificar que `/dev/shm/tcloud-transcription/` no acumula archivos tras procesar un lote (finally borra)
- [x] 10.12 Rollback: `php artisan migrate:rollback --step=1` (remueve `generate_alerts`), detener supervisor workers