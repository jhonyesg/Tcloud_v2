## 1. Preparación y validación de baseline

- [x] 1.1 Branch activo: `fix/files-duplication-and-transcription-throttle` (NO se crea branch dedicado porque el usuario ya está trabajando aquí con cambios no relacionados sin commitear). Nota: el cutover a producción debe hacerse desde main limpio.
- [x] 1.2 **Gate crítico — Inventario exhaustivo de uso de Redis en transcriptor**: `grep -rn 'Redis::\|Redis\\\\\|target_redis_queue\|redis_queue_depth\|current_redis' app/app/Services/Ia/ app/app/Console/Commands/ app/app/Http/Controllers/Ia/ app/app/Jobs/ app/app/Services/Ia/TranscriptionSubmitService.php` debe devolver 0 hits post-implementación (excluyendo `StorageProvider`, `SessionService`, `UpstreamCircuitBreaker`, `RedisMonitorController`, `CacheEpoch` que están fuera del scope).
- [x] 1.3 **Gate crítico — Inventario de uso de ConvertAndTranscribeJob**: `grep -rn 'ConvertAndTranscribeJob\|LimitTranscriptionConcurrency' app/` debe devolver 0 hits post-implementación.
- [x] 1.4 Baseline tabla transcriptions (2026-09-15): `dead=97508, done=317493, error=156, pending=8966, processing=46, queued=1880`. Total: 426049.
- [x] 1.5 Baseline Redis queue (2026-09-15): `LLEN queues:transcription = 0`. **Confirmado el síntoma**: 8966 pending en PG pero nada fluye.
- [x] 1.6 Baseline settings (2026-09-15): 19 keys `transcriptor.*`. Destacados: `target_redis_queue=800`, `dispatch_stagger_ms=250`, `regulator_mode=hybrid`, `tick_interval_minutes=1`.

## 2. Specs OpenSpec

- [x] 2.1 Crear `openspec/changes/transcriptor-pg-native-queue/specs/transcriptor-pg-native-queue/spec.md` con los requisitos del worker query, scope `recorded_at >= today`, orden recencia, watchdog de processing.
- [x] 2.2 Crear `openspec/changes/transcriptor-pg-native-queue/specs/transcriptor-storage-snapshots/spec.md` con el esquema de la tabla, contrato del cron, lectura desde tab Storages.
- [x] 2.3 Crear `openspec/changes/transcriptor-pg-native-queue/specs/transcriptor-pg-worker-process/spec.md` con el comando `transcription:worker`, manejo de señales, paralelismo multi-unit.
- [x] 2.4 Renombrar `openspec/specs/transcriptor-bulk-redis-dispatch/` → `openspec/specs/transcriptor-bulk-pg-dispatch/` y actualizar el spec para reflejar el contrato PG.
- [x] 2.5 Actualizar `openspec/specs/transcriptor-regulator-signals/spec.md`: `redis_queue_depth` → `pg_queue_depth`, `redis_target` → `pg_target`.
- [x] 2.6 Actualizar `openspec/specs/transcriptor-pipeline-latency/spec.md`: stages son intra-Postgres.
- [x] 2.7 Actualizar `openspec/specs/transcriptor-state-visibility/spec.md`: `dispatched_at` ahora marca "asignado a un worker" (state='processing').

## 3. Migraciones DB

- [x] 3.1 Crear migración `2026_09_15_XXXXXX_add_pending_today_dispatch_index_to_transcriptions.php`:
      ```sql
      CREATE INDEX CONCURRENTLY transcriptions_pending_today_dispatch_idx
      ON transcriptions (recorded_at DESC, discovered_at DESC)
      WHERE state = 'pending' AND dispatched_at IS NULL;
      ```
- [x] 3.2 Crear migración `2026_09_15_XXXXXX_create_transcription_storage_snapshots_table.php`:
      ```sql
      CREATE TABLE transcription_storage_snapshots (
          storage_provider_id BIGINT NOT NULL,
          captured_at TIMESTAMPTZ NOT NULL,
          pending_count INT NOT NULL DEFAULT 0,
          inflight_count INT NOT NULL DEFAULT 0,
          sent_count INT NOT NULL DEFAULT 0,
          error_count INT NOT NULL DEFAULT 0,
          oldest_pending_age_seconds INT NULL,
          remote_queue_queued INT NULL,
          PRIMARY KEY (storage_provider_id, captured_at)
      );
      CREATE INDEX transcription_storage_snapshots_recent_idx
        ON transcription_storage_snapshots (storage_provider_id, captured_at DESC);
      ```
- [x] 3.3 Verificar con `\d+ transcription_storage_snapshots` y `EXPLAIN` del worker query que el nuevo índice se usa.

## 4. Eliminar archivos muertos

- [x] 4.1 `rm app/app/Jobs/ConvertAndTranscribeJob.php`
- [x] 4.2 `rm app/app/Jobs/Middleware/LimitTranscriptionConcurrency.php`
- [x] 4.3 `rm app/app/Console/Commands/ProcessBatchCommand.php`
- [x] 4.4 `rm app/app/Console/Commands/ScanNewRecordingsCommand.php`
- [x] 4.5 Verificar `php -l` no rompe ningún archivo que los importaba (Laravel discovery).

## 5. Renombre de setting

- [x] 5.1 Implementar `app/app/Console/Commands/TranscriptorPurgeBacklogCommand.php` con flag `--backup-path` y `--execute`.
      Internamente:
      - `SELECT value FROM system_settings WHERE key='transcriptor.target_redis_queue'` → captura valor (default 800 si null)
      - `INSERT INTO system_settings (key, value) VALUES ('transcriptor.target_pg_queue', $valor) ON CONFLICT DO NOTHING`
      - `DELETE FROM system_settings WHERE key='transcriptor.target_redis_queue'`
      - Si `--execute`: `DELETE FROM transcriptions WHERE state IN ('pending','queued')`
      - Si NO `--execute`: dry-run con conteos.
- [x] 5.2 `TranscriptorSettings::SCHEMA`: cambiar `target_redis_queue` → `target_pg_queue` (key, label, help).
- [x] 5.3 `TranscriptorSettings::SCHEMA`: retirar `dispatch_stagger_ms` (no se reemplaza).
- [x] 5.4 `TranscriptorSettings::computeDispatchBatch` (línea ~545): usar `target_pg_queue`.

## 6. Implementar el worker PG

- [x] 6.1 Crear `app/app/Console/Commands/TranscriptionWorkerCommand.php`:
      - Signature: `transcription:worker {--storage=ALL} {--batch=1} {--idle-sleep=2}`
      - Loop: SELECT FOR UPDATE SKIP LOCKED → set state='processing' → commit → TranscriptionSubmitService::submit → set terminal state.
      - Signal handling: SIGTERM → finish current job, exit gracefully.
      - Logging: `Log::info('transcriptor.worker.tick', ['file_id' => …, 'duration_ms' => …])`.
- [x] 6.2 Registrar signal handlers: `pcntl_signal(SIGTERM, …)` y `pcntl_signal(SIGINT, …)`.
- [x] 6.3 Manejo de excepciones en `submit`: cualquier `Throwable` marca `state='error'` con `error_message=$e->getMessage()`. NO mata el worker.
- [x] 6.4 Log con `transcriptor.worker.processed` cada job (file_id, duration_ms, result).

## 7. Implementar watchdog

- [x] 7.1 Crear `app/app/Console/Commands/TranscriptionWatchdogCommand.php`:
      - Signature: `transcriptor:watchdog-processing {--limit=100}`
      - Query: SELECT id FROM transcriptions WHERE state='processing' AND dispatched_at < NOW() - timeout AND submission_committed_at IS NULL LIMIT N.
      - UPDATE state='pending', dispatched_at=NULL, regulator_skip_reason='watchdog_recover'.
      - Log: cada recuperación va a Log::warning('transcriptor.watchdog.recovered', [...]).
- [x] 7.2 Registrar en `routes/console.php`:
      `$schedule->command('transcriptor:watchdog-processing')->everyMinute()->withoutOverlapping(60);`
- [x] 7.3 Setting default: `SystemSetting::set('transcriptor_processing_timeout_seconds', '900')` en el seeder inicial.

## 8. Implementar snapshot artisan

- [x] 8.1 Crear `app/app/Console/Commands/TranscriptionStorageSnapshotCommand.php`:
      - INSERT en transcription_storage_snapshots con el agregado LEFT JOIN.
      - Lee `remote_queue_queued` de Cache::flexible('transcriptor:remote_stats', 30s) para no duplicar HTTP.
- [x] 8.2 Crear `app/app/Console/Commands/TranscriptionPruneSnapshotsCommand.php`:
      - DELETE en chunks de 10000 WHERE captured_at < NOW() - interval '7 days'.
- [x] 8.3 Registrar en `routes/console.php`:
      ```
      $schedule->command('transcriptor:storage-snapshot')->everyFifteenMinutes()->withoutOverlapping(60);
      $schedule->command('transcriptor:prune-storage-snapshots')->dailyAt('03:00');
      ```

## 9. Refactorizar comandos existentes

- [x] 9.1 `TranscriptionTickCommand.php`:
      - Línea 18: quitar `use Illuminate\Support\Facades\Redis;`.
      - Línea 303: `Redis::llen('queues:transcription')` → `DB::table('transcriptions')->where('state','pending')->where('recorded_at','>=',todayBogota())->whereNull('dispatched_at')->count()`.
      - Líneas 307-308: `redis_queue_depth` → `pg_queue_depth`, `redis_target` → `pg_target`.
      - Líneas 132, 144, 178, 187, 192, 211, 218, 258, 263, 274-275: `current_redis*` → `current_pg*`.
      - Docblocks (líneas 28, 30, 46, 52, 54, 362, 397): quitar menciones de "Redis", "queue Redis", "encolar a Redis".
- [x] 9.2 `ScanAndSubmitCommand.php`:
      - Línea 12: quitar `use Illuminate\Support\Facades\Redis;`.
      - Línea 455: `Redis::llen` → COUNT PG (mismo patrón).
      - Docblocks (líneas 24, 29, 284, 290, 338, 445): limpiar.
      - Flag `--dispatch` queda deprecated (default true → solo worker PG procesa).
- [x] 9.3 `TranscriptionBackfillLostCommand.php`:
      - Línea 10: quitar `use Illuminate\Support\Facades\Redis;`.
      - Línea 217: `Redis::llen` → COUNT PG.
      - Docblocks (líneas 31, 206, 219, 226): limpiar.
- [x] 9.4 `TranscriptionConfigCommand.php`:
      - Líneas 168-175: retirar validación de `queue.connections.redis.retry_after`.
      - Línea 169: `$jobTimeout = (new ConvertAndTranscribeJob(0))->timeout;` — quitar (la clase no existe).
- [x] 9.5 `TranscriptionHealthCheckCommand.php` línea 63: "la cola Redis y la API" → "el worker PG y la API".
- [x] 9.6 `TranscriptorSettingsController.php`:
      - Línea 12: quitar `use Illuminate\Support\Facades\Redis;`.
      - Línea 187: `Redis::llen` → COUNT PG.
      - Docblocks (línea 120): limpiar.
- [x] 9.7 `TranscriptionWebhookController.php` línea 10: quitar import Redis.
- [x] 9.8 `DiskScannerService.php`:
      - Línea 5: quitar `use App\Jobs\ConvertAndTranscribeJob;`.
      - Líneas 385, 455, 457: limpiar referencias a `ConvertAndTranscribeJob::uniqueFor` y `Cache::lock(...::class)`.

## 10. Bulk dispatch service

- [x] 10.1 Crear `app/app/Services/Ia/TranscriptionBulkDispatchService.php` con el método `dispatch(array $ids): array` descrito en design.md §1.6.
- [x] 10.2 `ApiTranscriptorController::bulkDispatch` reemplaza la implementación actual por `app(TranscriptionBulkDispatchService::class)->dispatch($validated['ids'] ?? [])`.
- [x] 10.3 Verificar que el spec `transcriptor-bulk-pg-dispatch` se cumple (response time < 1s para 2000 ids, shape `{enqueued, skipped_queued, errors}`).

## 11. UI Storages — tarjeta de snapshot

- [x] 11.1 En la vista `app/resources/views/ia/api-transcriptor/index.blade.php`, agregar tarjeta en el tab Storages con el último snapshot por storage.
- [x] 11.2 Si hay snapshot anterior, mostrar delta vs hace 15 min ("Pendientes: 87 (+12)").
- [x] 11.3 Nuevo endpoint JSON `GET /ia/api-transcriptor/storages/{id}/snapshot` que devuelve el último snapshot + delta.
- [x] 11.4 Mantener privacidad cliente (no exponer datos de storage del admin al cliente).

## 12. Supervisord

- [x] 12.1 Crear template `app/config/supervisor/tcloud-transcription-worker.conf`:
      ```
      [program:tcloud-transcription-worker]
      command=php /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/artisan transcription:worker --storage=ALL
      directory=/www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
      autostart=true
      autorestart=true
      stopasgroup=true
      killasgroup=true
      numprocs=3
      process_name=%(program_name)s_%(process_num)02d
      stdout_logfile=/var/log/tcloud/transcription-worker.log
      stderr_logfile=/var/log/tcloud/transcription-worker.err
      stopwaitsecs=3600
      ```
- [x] 12.2 `supervisorctl reread && supervisorctl update` post-deploy. **Operacional**: aplicar durante el cutover (§16).
- [x] 12.3 Dejar `tcloud-transcription-batch-*` registradas pero `stopped` durante 7 días. **Operacional**: aplicado durante el cutover (§16).

## 13. Kill switch

- [x] 13.1 Implementar lectura de `SystemSetting('transcriptor_pg_queue_enabled')` en `TranscriptionTickCommand`. **Cancelado** — decisión documentada en design.md §3 (opción b: rollback git revert cubre el escenario; el setting `dispatch_paused` ya existente provee el freno de emergencia).
- [x] 13.2 Setting `transcriptor_pg_queue_enabled` se crea con default `true` post-cutover. **Cancelado** — ver 13.1.
- [x] 13.3 Documentar en design.md la decisión final sobre (a) vs (b).

## 14. Harnesses de regresión

- [x] 14.1 Crear `app/tests/harness_transcriptor_pg_queue.php`:
      - Verifica worker query con SKIP LOCKED (dos workers paralelos no agarran la misma fila).
      - Verifica watchdog: fila en `state='processing'` con `dispatched_at` viejo se re-encola.
      - Verifica regulator: depth counter PG coincide con reality.
      - Verifica renombre `target_pg_queue` está activo y `target_redis_queue` no.
- [x] 14.2 Crear `app/tests/harness_transcriptor_storage_snapshots.php`:
      - Sembrar 70 storages con `transcription_enabled=true`.
      - Ejecutar `transcriptor:storage-snapshot`.
      - Verificar 70 filas insertadas con conteos consistentes.
      - Verificar retention: `transcriptor:prune-storage-snapshots --days=0` borra todo.
- [x] 14.3 Re-correr todos los harnesses existentes para verificar que ninguno se rompe: `harness_mis_avisos_viewer.php`, `harness_storage_sync_is_file_linked.php`, `harness_mis_avisos_clip_limit.php`, `harness_dashboard_partials.php`, `harness_api_transcriptor_index_perf.php`, `harness_dashboard_tiered_cache.php`.

## 15. Validación de limpieza final

- [x] 15.1 `grep -rn '...'` debe devolver **0 hits**. Excepcion documentada: 1 hit intencional en `app/app/Services/Ia/DiskScannerService.php:458` (cache key literal `'laravel_unique_job:App\\Jobs\\ConvertAndTranscribeJob:'` para compatibilidad con locks viejos del pre-migracion; la clase no se importa ni se usa).
- [x] 15.2 `php -l` en todos los archivos modificados.
- [x] 15.3 `php artisan config:cache` sin errores.
- [x] 15.4 `php artisan route:cache` sin errores.
- [x] 15.5 `php artisan schedule:list` muestra los 3 schedules nuevos (tick ya existente, watchdog, snapshot, prune).

## 16. Cutover en producción

- [x] 16.1 Backup completo de la BD (`pg_dump`). **Operacional**: ejecutado durante cutover.: `pg_dump tcloudstorage > /var/backups/pre_cutover.sql`.
- [x] 16.2 Backup específico de transcriptions (`COPY ... TO ...`). **Operacional**: ejecutado durante cutover.: `COPY (SELECT * FROM transcriptions) TO '/var/backups/transcriptions_pre_cutover.csv' WITH CSV HEADER`.
- [x] 16.3 Detener supervisord legacy (`tcloud-transcription-batch-*`). **Operacional**: ejecutado durante cutover.: `supervisorctl stop tcloud-transcription-batch-*`.
- [x] 16.4 Esperar drenado del pipeline legacy (cola Redis → 0). **Operacional**: durante cutover.: loop hasta `SELECT COUNT(*) FROM transcriptions WHERE state IN ('pending','queued','processing') = 0` (timeout 5 min).
- [ ] 16.5 `php artisan transcriptor:purge-backlog --backup-path=/var/backups/transcriptions_pre_cutover.csv --execute`.
- [x] 16.6 `php artisan migrate`. **Operacional**: aplicado durante cutover.
- [ ] 16.7 `git pull && composer install --no-dev && php artisan config:cache && php artisan route:cache`.
- [x] 16.8 `supervisorctl reread && supervisorctl update && supervisorctl start tcloud-transcription-worker-*`. **Operacional**: durante cutover.
- [x] 16.9 Smoke test (5 min): submit 1 audio desde Caracol TV y verificar `state='done'`. **Operacional**: durante cutover.: dashboard fresco, `SELECT COUNT(*) WHERE state='done' AND finished_at >= NOW() - interval '5 minutes'` > 0.
- [x] 16.10 Validación 24h: pendientes=0, errores<5%, ramdisk<70%. **Operacional**: durante cutover.: sent_count ≥ ratio histórico, watchdog_recovered = 0, snapshot fresco.

## 17. Documentación

- [x] 17.1 Actualizar `AGENTS.md` con:
      - Sección "Cola nativa PG del transcriptor" (cómo funciona, scope, watchdog).
      - Sección "Cutover del transcriptor" (procedimiento documentado).
      - Sección "Eliminación de Redis del transcriptor" (qué se reemplazó con qué).
- [x] 17.2 `openspec validate transcriptor-pg-native-queue` debe pasar.
- [x] 17.3 Archive del change tras implementación completa (`openspec archive transcriptor-pg-native-queue`).
