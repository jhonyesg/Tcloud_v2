# Design — transcriptor-pg-native-queue

## 1. Decisiones de diseño que el proposal no cubre

### 1.1 Concurrencia: workers múltiples, no funnel Redis

Hoy `LimitTranscriptionConcurrency` (Redis funnel) limita cuántos ffmpeg corren
simultáneos **dentro de un solo proceso PHP-FPM**. En el modelo nuevo cada
unit supervisord corre su propio loop worker, sin funnel. El paralelismo se
logra con N units.

| Aspecto | Antes | Después |
|---|---|---|
| Proceso que ejecuta ffmpeg | queue:work (N units) | transcription:worker (N units) |
| Concurrencia por proceso | funnel Redis (N=variable por setting) | 1 ffmpeg a la vez (cada worker) |
| Total de ffmpeg simultáneos | N_units × funnel_limit | N_units × 1 |
| Configuración | `inflight_max` (Redis) | N_units en supervisord.conf |

**Tradeoff**: se pierde el ajuste fino intra-proceso. A cambio: cero Redis en
el camino crítico, paralelismo explícito en supervisord.conf (visible en
`supervisorctl status`), y la métrica `inflight_active` se calcula con un
COUNT PG barato (~3 ms con índice).

### 1.2 Estado `processing` y watchdog

El nuevo worker hace la transición de estados **atómicamente** con `FOR UPDATE
SKIP LOCKED`:

```
Worker loop:
  BEGIN
  row = SELECT * FROM transcriptions
        WHERE state='pending'
          AND dispatched_at IS NULL
          AND recorded_at >= today
        ORDER BY recorded_at DESC, discovered_at DESC
        LIMIT 1
        FOR UPDATE SKIP LOCKED;
  
  if no row: sleep(2s); continue;
  
  UPDATE transcriptions
     SET state='processing', dispatched_at=NOW()
   WHERE id = row.id;
  COMMIT;  -- libera el lock aquí
  
  -- ahora ffmpeg + POST (sin lock de BD)
  result = TranscriptionSubmitService::submit(row);
  
  if result.ok:
    UPDATE state='queued', job_id=..., submission_committed_at=NOW();
  if result.requeueable:
    UPDATE state='pending', requeue_after_at=..., regulator_skip_reason=...;
  if result.error:
    UPDATE state='error'|'dead', error_message=..., finished_at=...;
```

El `COMMIT` después del UPDATE libera el lock. Si el worker muere entre el
COMMIT y el final del submit, la fila queda en `state='processing'` con
`dispatched_at` set pero sin `submission_committed_at`. El watchdog la detecta
y la re-encola (ver §1.4).

### 1.3 Orden y scope del worker query

```sql
SELECT *
FROM transcriptions
WHERE state = 'pending'
  AND dispatched_at IS NULL
  AND recorded_at >= (date_trunc('day', now() AT TIME ZONE 'America/Bogota'))
ORDER BY recorded_at DESC, discovered_at DESC
LIMIT $batch
FOR UPDATE SKIP LOCKED;
```

- `recorded_at DESC`: audios más recientes primero.
- `discovered_at DESC` (desempate): si dos audios tienen el mismo recorded_at,
  gana el descubierto más tarde (llegó a la cola después).
- `state = 'pending'`: solo filas que aún no entraron a process.
- `dispatched_at IS NULL`: defensa contra race con un dispatch manual que haya
  marcado la fila sin cambiarle state.
- `recorded_at >= today` (Bogota): scope "solo hoy" como pidió el operador.

Índice necesario:

```sql
CREATE INDEX CONCURRENTLY transcriptions_pending_today_dispatch_idx
ON transcriptions (recorded_at DESC, discovered_at DESC)
WHERE state = 'pending' AND dispatched_at IS NULL;
```

Sin este índice el plan sería: `transcriptions_pending_dispatchable_idx`
(filtra state+dispatched_at) + sort. Con el nuevo índice: index-only scan en
orden, sin sort.

### 1.4 Watchdog — `transcriptor:watchdog-processing`

```sql
-- candidates: filas que llevan demasiado en 'processing'
SELECT id, file_id, dispatched_at, updated_at
FROM transcriptions
WHERE state = 'processing'
  AND dispatched_at < now() - interval '900 seconds'
  AND submission_committed_at IS NULL
ORDER BY dispatched_at ASC
LIMIT 100;

-- para cada candidato:
UPDATE transcriptions
   SET state = 'pending',
       dispatched_at = NULL,
       regulator_skip_reason = 'watchdog_recover',
       updated_at = NOW()
 WHERE id = $id;
```

- Cadencia: cron cada 60 s (`* * * * * php artisan transcriptor:watchdog-processing`).
- Timeout: 900 s por defecto (configurable via
  `SystemSetting('transcriptor_processing_timeout_seconds')`).
- Log: cada candidato recuperado va a `Log::warning('transcriptor.watchdog.recovered', [...])`.
- Métrica: contador `transcriptor:watchdog:recovered_total` en cache, leído por
  el snapshot.

### 1.5 Snapshot — `transcriptor:storage-snapshot`

```sql
INSERT INTO transcription_storage_snapshots (
    storage_provider_id, captured_at,
    pending_count, inflight_count, sent_count, error_count,
    oldest_pending_age_seconds, remote_queue_queued
)
SELECT
    sp.id AS storage_provider_id,
    NOW() AS captured_at,
    COUNT(*) FILTER (WHERE t.state = 'pending'
                       AND t.recorded_at >= today_bogota) AS pending_count,
    COUNT(*) FILTER (WHERE t.state = 'processing') AS inflight_count,
    COUNT(*) FILTER (WHERE t.state = 'done'
                       AND t.finished_at >= NOW() - interval '15 minutes') AS sent_count,
    COUNT(*) FILTER (WHERE t.state IN ('error','dead')
                       AND t.finished_at >= NOW() - interval '15 minutes') AS error_count,
    EXTRACT(EPOCH FROM NOW() - MIN(t.dispatched_at)
            ) FILTER (WHERE t.state = 'pending') AS oldest_pending_age_seconds,
    $remoteQueueQueued AS remote_queue_queued
FROM storage_providers sp
LEFT JOIN files f ON f.storage_provider_id = sp.id
LEFT JOIN transcriptions t ON t.file_id = f.id
WHERE sp.transcription_enabled = true
GROUP BY sp.id
ON CONFLICT (storage_provider_id, captured_at) DO NOTHING;
```

- Cadencia: cron cada 15 min (`*/15 * * * *`).
- `remote_queue_queued` se lee una vez de `/api/metrics/overview` antes del
  INSERT (mismo `Cache::flexible('transcriptor:remote_stats', 30s)` que ya usa
  el regulador — sin golpe doble al upstream).
- Retención: job nocturno `transcriptor:prune-storage-snapshots --days=7` con
  `DELETE WHERE captured_at < NOW() - interval '7 days' LIMIT 10000` en loop.

### 1.6 Bulk-dispatch endpoint — nuevo servicio

`POST /ia/api-transcriptor/jobs/bulk-dispatch` deja de llamar a
`ConvertAndTranscribeJob::dispatch()` y pasa a `TranscriptionBulkDispatchService::dispatch(array $ids)`:

```php
public function dispatch(array $ids): array {
    if (empty($ids)) {
        // auto-select: hasta 2000 pendientes sin dispatched_at
        $ids = Transcription::where('state', 'pending')
            ->whereNull('dispatched_at')
            ->where('recorded_at', '>=', todayBogota())
            ->orderBy('recorded_at', 'desc')
            ->limit(2000)
            ->pluck('id')
            ->all();
    }

    $enqueued = 0;
    $skipped  = 0;
    $errors   = 0;

    DB::transaction(function () use ($ids, &$enqueued, &$skipped, &$errors) {
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Transcription::whereIn('id', $chunk)->get();
            foreach ($rows as $row) {
                if (in_array($row->state, ['done','error','dead'], true)) {
                    $skipped++; continue;
                }
                if (!is_null($row->job_id)) {
                    $skipped++; continue;
                }
                $row->update([
                    'dispatched_at' => now(),
                    'state'         => 'processing',
                ]);
                $enqueued++;
            }
        }
    });

    return ['enqueued' => $enqueued, 'skipped_queued' => $skipped, 'errors' => $errors];
}
```

La respuesta es idéntica a la spec actual. Internamente el bulk-dispatch pone
filas a `state='processing'`, así que el worker PG **NO las toma** (el filtro
del worker es `state='pending'`). El bulk dispatch procesa sincrónicamente
mediante `TranscriptionSubmitService` (como hacía la versión síncrona manual
hoy).

### 1.7 Cutover — secuencia exacta

```
DÍA DEL CUTOVER
═══════════════

PRE (5 min antes):
  □ git pull en el server de prod
  □ git status limpio
  □ DB dump completo como red de seguridad: pg_dump tcloudstorage > /var/backups/pre_cutover.sql
  □ transcriptions backup específico: 
      COPY (SELECT * FROM transcriptions) TO '/var/backups/transcriptions_pre_cutover.csv' WITH CSV HEADER

PASO 1 — Detener supervisord legacy:
  supervisorctl stop tcloud-transcription-batch-*
  
  Verificar: supervisorctl status | grep transcription
    → todas las batch-* deben estar "STOPPED"

PASO 2 — Esperar drenado del pipeline (pollings, jobs en vuelo):
  Loop:
    pending_count = SELECT COUNT(*) FROM transcriptions WHERE state IN ('pending','queued','processing');
    if pending_count == 0: break;
    sleep(5);
  Timeout: 5 minutos. Si no drena, abortar cutover y reanudar supervisord legacy.

PASO 3 — Setting rename (transactor:purge-backlog):
  php artisan transcriptor:purge-backlog --backup-path=/var/backups/transcriptions_pre_cutover.csv --execute
  
  Internamente:
    - Lee transcriptor.target_redis_queue → 800
    - UPSERT transcriptor.target_pg_queue = 800
    - DELETE FROM system_settings WHERE key='transcriptor.target_redis_queue'
    - DELETE FROM transcriptions WHERE state IN ('pending','queued')

PASO 4 — Migración nueva (índice + tabla snapshots):
  cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
  php artisan migrate
  
  Verificar:
    SELECT indexname FROM pg_indexes WHERE indexname='transcriptions_pending_today_dispatch_idx';
    \d transcription_storage_snapshots

PASO 5 — Deploy código nuevo:
  git checkout <branch>
  composer install --no-dev --optimize-autoloader
  php artisan config:cache
  php artisan route:cache

PASO 6 — Iniciar supervisord nuevo:
  supervisorctl reread
  supervisorctl update
  supervisorctl start tcloud-transcription-worker-*
  
  Verificar:
    supervisorctl status | grep worker
      → todas "RUNNING"

PASO 7 — Smoke test (5 min):
  - Dashboard: aparece el snapshot fresco en 15 min
  - Tinker:
      SELECT COUNT(*) FROM transcriptions WHERE state='done' AND finished_at >= NOW() - interval '5 minutes';
    → debe ser > 0 si hay audios de hoy en el storage
  - grep "transcriptor.tick" /var/log/laravel.log → debe mostrar pg_queue_depth, no redis_queue_depth

VALIDACIÓN POST (24h después):
  - Comparar sent_count vs histórico: ≥ ratio anterior
  - Sin watchdog_recover > 0 (no debería haber ni uno)
  - transcriptor.processing_timeout_seconds estable en 900
  - /api/metrics/overview.queue_queued refleja envíos (no cola muerta)
```

### 1.8 Error scenarios

| Escenario | Comportamiento |
|---|---|
| `/dev/shm` lleno | `TranscriptionSubmitService::markRequeueable` actual a `state='pending'`, `requeue_after_at=NOW()+60s`. Worker reintenta en el siguiente ciclo. |
| API upstream 429 | `UpstreamRateLimitException` → `markRequeueable` con `retry_after` del header. |
| API upstream 503 | `UpstreamUnavailableException` → `markRequeueable` + `UpstreamCircuitBreaker::recordStrike()`. |
| Worker crashea en ffmpeg | Fila queda en `state='processing'` sin `submission_committed_at`. Watchdog la re-encola en ≤ 60s. |
| DOS workers竞争的 misma fila | `FOR UPDATE SKIP LOCKED` lo evita. Worker B no ve la fila hasta que A hace COMMIT. |
| DELETE en cutover tarda mucho | Lock de tabla durante el DELETE. Tiempo estimado: 8.284 filas × ~1ms = ~10s. Aceptable. Si >60s, hacer en chunks de 1000. |
| Snapshot query pesada | Se cachea `storage_providers` transcritos (≈70 filas) y se hace LEFT JOIN. Tiempo esperado: <500ms con índices. |
| DiskScannerService sigue referenciando `ConvertAndTranscribeJob` | El patch borra el import + las 3 líneas (líneas 385, 455, 457). Sin esto, PHP parse falla. |

### 1.9 Decisiones de naming

| Antes | Después | Por qué |
|---|---|---|
| `target_redis_queue` (setting) | `target_pg_queue` | Una IA en 2027 que vea "redis" asume Redis. PG es la verdad. |
| `redis_queue_depth` (decision key) | `pg_queue_depth` | Mismo motivo. |
| `redis_target` (decision value) | `pg_target` | Mismo motivo. |
| `current_redis` (log key) | `current_pg` | Mismo motivo. |
| `ConvertAndTranscribeJob` (clase) | (eliminado) | El worker PG llama a `TranscriptionSubmitService` directamente. |
| `LimitTranscriptionConcurrency` (middleware) | (eliminado) | Concurrencia = N units supervisord. |
| `dispatch_stagger_ms` (setting) | (retirado) | Sin dispatch step, sin stagger entre dispatches. El ritmo lo controla el regulador. |
| `redis_session_count` (UI RedisMonitor) | (sin cambio) | Sesiones siguen en Redis. No en scope. |

---

## 2. Estructura de archivos del nuevo módulo

```
app/app/Console/Commands/
├── TranscriptionTickCommand.php         [MOD: quitar Redis, renombrar keys]
├── TranscriptionBackfillLostCommand.php [MOD: quitar Redis llen]
├── ScanAndSubmitCommand.php             [MOD: limpiar docblocks]
├── TranscriptionConfigCommand.php       [MOD: quitar validación Redis]
├── TranscriptionHealthCheckCommand.php  [MOD: texto de ayuda]
├── TranscriptionWorkerCommand.php       [NEW: poleador PG]
├── TranscriptionWatchdogCommand.php     [NEW: recovery de processing zombies]
├── TranscriptionStorageSnapshotCommand.php [NEW: snapshot 15min]
├── TranscriptionPruneSnapshotsCommand.php  [NEW: retention 7d]
└── TranscriptorPurgeBacklogCommand.php  [NEW: cutover DELETE + setting rename]

app/app/Services/Ia/
├── TranscriptorSettings.php             [MOD: target_pg_queue + retirar stagger]
├── TranscriptionSubmitService.php       [SIN CAMBIO: ya invocado por el worker]
└── TranscriptionBulkDispatchService.php [NEW: bulk dispatch endpoint]

app/app/Jobs/
├── ConvertAndTranscribeJob.php          [DELETE]
└── Middleware/
    └── LimitTranscriptionConcurrency.php [DELETE]

app/app/Http/Controllers/Ia/
├── ApiTranscriptorController.php        [MOD: bulkDispatch llama al service]
└── TranscriptorSettingsController.php   [MOD: queueDepth usa COUNT PG]

app/app/Services/Ia/
├── DiskScannerService.php               [MOD: limpiar refs a ConvertAndTranscribeJob]
└── TranscriptionSubmitService.php       [SIN CAMBIO]

app/app/Http/Controllers/Webhooks/
└── TranscriptionWebhookController.php   [MOD: quitar import Redis]

app/database/migrations/
├── 2026_09_15_XXXXXX_add_pending_today_dispatch_index_to_transcriptions.php [NEW]
└── 2026_09_15_XXXXXX_create_transcription_storage_snapshots_table.php       [NEW]

app/config/supervisor/
└── tcloud-transcription-worker.conf     [NEW: unit template]
```

---

## 3. Decisión kill switch (tareas 13.1, 13.2, 13.3)

Decisión final: **opción (b)** — eliminar el kill switch `transcriptor_pg_queue_enabled`.
El rollback completo vía `git revert <commit>` + `php artisan config:cache` cubre
todo lo que el flag cubriría, y mantener una versión stub de `ConvertAndTranscribeJob`
solo para el kill switch introduce complejidad y deuda técnica.

Razones:
1. La única diferencia entre el camino "kill switch ON" y el rollback git revert
   es el tiempo de ejecución (~30 segundos vs 1-2 minutos). Ningún escenario
   conocido justifica esa diferencia: el watchdog recupera filas zombies en
   ≤ 60 s y `supervisorctl restart tcloud-transcription-worker-*` se hace en
   segundos sin deploy.
2. Mantener una versión stub de `ConvertAndTranscribeJob::dispatch` que
   "marca dispatched_at" sin enviar genera filas que el worker no toma pero
   que la UI muestra como "processing" — ruido innecesario.
3. El setting `dispatch_paused` (existente, sin cambios) sigue siendo el
   freno de emergencia operativo. Combinado con `TRANSCRIPTOR_DISPATCH_PAUSED`
   en . env, da cobertura completa para los casos que el kill switch
   originalmente buscaba.

Consecuencias:
- Tarea 13.1 (lectura del setting en TranscriptionTickCommand) → cancelada.
- Tarea 13.2 (SystemSetting default 'true' post-cutover) → cancelada.
- Tarea 13.3 (este docblock) → cumplido.
