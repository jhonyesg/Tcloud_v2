# transcriptor-pg-native-queue

## Why

El módulo `/ia/api-transcriptor` despacha hacia una cola Redis (`queues:transcription`)
**además** de mantener el estado de cada trabajo en la tabla PostgreSQL `transcriptions`.
Esa doble fuente de verdad produce cinco síntomas observados:

1. **Backlog opaco**: hoy 8.284 filas `pending` con delay promedio de despacho = 3.7 h.
   El operador no puede responder "¿cuántos audios de hoy están pendientes en el
   storage X?" sin un JOIN pesado `transcriptions → files → storage_providers`.
2. **Regulador ciego cuando Redis cae**: `TranscriptionTickCommand::evaluateRegulator`
   usa `Redis::llen('queues:transcription')` como contador de profundidad
   (`app/app/Console/Commands/TranscriptionTickCommand.php:303`). Si Redis se cae o
   se desincroniza, el regulador frena o dispara de forma incorrecta.
3. **Imposible saber qué API remota está haciendo**: el upstream expone
   `/api/metrics/overview` con `queue_queued`, `ram_pct`, `ramdisk_pct`, pero la UI
   consumidora (pestaña Consumo, archivada en `simplify-api-transcriptor`) ya no existe,
   así que el operador opera a ciegas sobre lo que la GPU remota está procesando.
4. **FIFO obsoleto para un sistema de radio en vivo**: el despacho actual procesa
   por `created_at ASC`. Un audio de las 23:00 del día anterior descubierto a las
   03:00 de hoy se inserta al final de la cola, mientras un audio de las 02:59
   descubierto a las 02:00 se procesa primero — al revés de lo que el operador
   quiere (lo más fresco primero).
5. **Nombres engañosos que confunden a otras IAs**: el código, los settings
   (`target_redis_queue`), las decision keys (`redis_queue_depth`) y ~20 docblocks
   siguen diciendo "Redis" / "queue Redis" cuando el módulo va a usar exclusivamente
   PostgreSQL. Un lector (humano o IA) que vea `Redis::llen` y `target_redis_queue`
   en 2027 va a asumir que la cola vive en Redis y va a diseñar soluciones
   equivocadas.

## What Changes

### Núcleo: la cola vive en PostgreSQL

- `transcriptions` deja de ser **solo** un registro y pasa a ser también la **cola**.
  Su forma ya es compatible (`state`, `dispatched_at`, `recorded_at`, FK `file_id
  UNIQUE`) — no requiere nueva tabla para la cola.
- El worker consume con `SELECT … FOR UPDATE SKIP LOCKED` directo sobre la tabla.
  Elimina el driver `redis` para `ConvertAndTranscribeJob::onQueue('transcription')`.
- El regulador (`evaluateRegulator`) deja de leer `Redis::llen` y pasa a leer
  `COUNT(*) FROM transcriptions WHERE state IN ('pending','queued') AND
  recorded_at >= today`. La señal remota (`/api/metrics/overview`) no cambia.
- El worker es un **proceso separado** (W2), supervisado por una nueva unit
  systemd `tcloud-transcription-worker-*` (estilo `queue:work` pero poleando PG).
  El tick (`transcription:tick`) queda como **planner puro**: discovery + decisión
  del regulador + asignación de trabajo, sin tocar ffmpeg ni la API externa.
- Watchdog: filas que pasan a `state='processing'` pero no reciben
  `submission_committed_at` en `transcriptor.processing_timeout_seconds` (default
  900s) se reponen a `state='pending'` con `regulator_skip_reason='watchdog_recover'`.

### Alcance temporal: solo HOY

- Filtro del worker: `recorded_at >= today 00:00 (Bogota)`.
- Audio con `recorded_at < today` queda en `state='pending'` pero NO es candidato
  a despacho. Su destino es la purga de cutover (siguiente bullet).
- Orden del despacho: `ORDER BY recorded_at DESC, discovered_at DESC` (más fresco
  primero, desempate por descubrimiento).

### Purga de cutover (one-shot)

- Antes del cutover: `DELETE FROM transcriptions WHERE state IN ('pending','queued')`
  (≈ 8.284 filas estimadas a 2026-09-15). Justificación: el operador confirmó que
  los pendientes pre-migración no tienen valor (decisión de exploracion 2026-09-15,
  alternativa "pasarlo a `state='dead'`" descartada porque "no soluciona, mejor
  eliminarlo y se escanea y se lista mejor").
- Pre-requisito obligatorio: backup lógico (`pg_dump --table=transcriptions` o
  `COPY transcriptions TO '/var/backups/transcriptions_pre_cutover.csv'`).
- Procedimiento ejecutable: nuevo artisan `transcriptor:purge-backlog` con flag
  `--backup-path` y `--execute` (default dry-run).

### Observabilidad por storage cada 15 minutos

- Nueva tabla `transcription_storage_snapshots` con una fila por
  `(storage_provider_id, captured_at)` cada 15 minutos, capturando:
  - `pending_count` — `COUNT(*) WHERE state='pending' AND recorded_at >= today`.
  - `inflight_count` — `COUNT(*) WHERE state='processing'`.
  - `sent_count` — `COUNT(*) WHERE state='done' AND finished_at >= captured_at - 15m`.
  - `error_count` — `COUNT(*) WHERE state IN ('error','dead') AND finished_at >=
    captured_at - 15m`.
  - `oldest_pending_age_seconds` — `EXTRACT(EPOCH FROM (now() - MIN(dispatched_at)
    WHERE state='pending'))`, NULL si no hay pendientes.
  - `remote_queue_queued` — valor de `/api/metrics/overview.queue_queued` al
    momento del snapshot (para graficar "cola local vs cola remota sin pagar HTTP
    en cada render").
- Nuevo artisan `transcriptor:storage-snapshot` invocado por cron cada 15 min.
  Reemplaza la query ad-hoc que hoy se hace desde la UI removida.
- La tarjeta en el tab Storages de `/ia/api-transcriptor` muestra el último
  snapshot + delta vs el anterior ("Pendientes: 87 (+12 vs hace 15 min)").

### Kill switch sin deploy

- Nuevo flag `SystemSetting('transcriptor_pg_queue_enabled')` (default `true`
  después del cutover). Cuando `false`, el tick cae al path legacy
  `ConvertAndTranscribeJob::dispatch()` (Redis) en lugar del worker PG.
- Complementa los ya existentes `dispatch_paused` (freno total) y
  `TRANSCRIPTOR_DISPATCH_PAUSED` (env override).
- Permite rollback operativo en <2 minutos sin tocar código, en lo que se
  diagnostica cualquier regresión del nuevo path.

### Endpoint bulk-dispatch

- `POST /ia/api-transcriptor/jobs/bulk-dispatch` deja de encolar a Redis y pasa
  a marcar `dispatched_at = now()` + `state='processing'` directamente sobre las
  filas `pending`/`queued` válidas.
- Renombrado de spec: `transcriptor-bulk-redis-dispatch` →
  `transcriptor-bulk-pg-dispatch`. Misma firma externa (`{ ids: int[] }`), mismo
  shape de respuesta (`{ enqueued, skipped_queued, errors }`), semántica de
  respuesta <1 s para ≤ 2000 ids.

### Limpieza de código muerto y renombre masivo (deuda técnica)

#### Archivos a eliminar (4)

- `app/app/Jobs/ConvertAndTranscribeJob.php` — Cola = `transcriptions` directo.
  El `handle()` se fusiona en el nuevo worker.
- `app/app/Jobs/Middleware/LimitTranscriptionConcurrency.php` — Su único caller
  era `ConvertAndTranscribeJob::middleware()`. Concurrencia = N units supervisord.
- `app/app/Console/Commands/ProcessBatchCommand.php` — Ya stub-DEPRECATED.
- `app/app/Console/Commands/ScanNewRecordingsCommand.php` — Ya stub-DEPRECATED.

#### Archivos a refactorizar (9)

- `app/app/Services/Ia/TranscriptorSettings.php` — Setting `target_redis_queue`
  → `target_pg_queue` (label, help text, `computeDispatchBatch`).
- `app/app/Console/Commands/TranscriptionTickCommand.php` — Eliminar
  `Redis::llen`, renombrar `redis_queue_depth`→`pg_queue_depth`,
  `redis_target`→`pg_target`, `current_redis*`→`current_pg*` en logs y
  mensajes, limpiar `use Redis`, actualizar ~20 docblocks.
- `app/app/Console/Commands/ScanAndSubmitCommand.php` — Eliminar
  `Redis::llen`; flag `--dispatch` queda deprecated; limpiar docblocks.
- `app/app/Console/Commands/TranscriptionBackfillLostCommand.php` — Eliminar
  `Redis::llen`; reemplazar por `COUNT(*)` PG con filtro `recorded_at >= today`.
- `app/app/Console/Commands/TranscriptionConfigCommand.php` — Eliminar
  validación `queue.connections.redis.retry_after` (no aplica sin job Bus).
- `app/app/Console/Commands/TranscriptionHealthCheckCommand.php` — Texto de
  ayuda: "la cola Redis y la API" → "el worker PG y la API".
- `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php` — Endpoint
  `queueDepth` deja de leer Redis llen; usa `COUNT(*)` PG.
- `app/app/Http/Controllers/Webhooks/TranscriptionWebhookController.php` —
  Eliminar import Redis no usado.
- `app/app/Services/Ia/DiskScannerService.php` — Limpiar referencias a
  `ConvertAndTranscribeJob::uniqueFor` y `Cache::lock(...::class)`.

#### Setting renombrado (data, no schema)

```
transcriptor.target_redis_queue (valor actual: 800) → transcriptor.target_pg_queue (800)
```

Procedimiento (paso 4 del cutover): INSERT el valor viejo bajo el nombre nuevo,
DELETE el viejo. Manejado por el mismo artisan `transcriptor:purge-backlog`.

#### Setting retirado (decisión de exploración 2026-09-15)

- `dispatch_stagger_ms` (system_settings + clave en TranscriptorSettings): sin
  efecto post-migración (no hay "dispatch" como paso distinto). El control de
  ritmo lo ejerce el regulador (lee `/api/metrics/overview`) y el guardrail de
  `/dev/shm` ya existente en `TranscriptionSubmitService`. **No se reemplaza
  por `worker_stagger_ms`** porque introduce una pausa artificial sin valor.

### Unidades supervisord

- Nuevas: `tcloud-transcription-worker-*` ejecutando `transcription:worker
  --storage=ALL --log=<unit-specific>`. N unidades (default 3) según carga.
- Viejas: `tcloud-transcription-batch-*` se mantienen **registradas pero
  deshabilitadas** en `supervisord.conf` durante 7 días post-cutover. Sirven
  como red de seguridad si `transcriptor_pg_queue_enabled=false` se activa.

### Specs nuevas

- `transcriptor-pg-native-queue` — Contrato de la cola en PG, query del worker,
  prioridad recencia, watchdog de processing.
- `transcriptor-storage-snapshots` — Esquema de la tabla, contrato del cron,
  lectura desde el tab Storages.
- `transcriptor-pg-worker-process` — Comando `transcription:worker`, unit
  supervisord, manejo de señales, paralelismo.

### Specs actualizadas

- `transcriptor-bulk-redis-dispatch` → renombrada a `transcriptor-bulk-pg-dispatch`
  (cambia contrato: ya no encola a Redis).
- `transcriptor-regulator-signals` — `redis_queue_depth` reemplazado por
  `pg_queue_depth`. Resto de señales (remote_ram_pressure, remote_ramdisk_pressure,
  remote_queue_full, shm_low, inflight_full, upstream_circuit) **sin cambio**.
- `transcriptor-pipeline-latency` — Los stages `dispatched_to_committed` y
  `committed_to_finished` se mantienen conceptualmente pero ahora son intra-Postgres
  en lugar de Postgres↔Redis.
- `transcriptor-state-visibility` — `dispatched_at` cambia de significado: ya no
  marca "encolado a Redis" sino "asignado a un worker" (`state='processing'`).

## Contrato con módulos dependientes (NO se tocan)

El módulo API Transcriptor es el **proveedor** de la materia prima que consumen
los módulos de Correcciones y Avisos Inteligentes. El siguiente contrato se
preserva íntegro — la pérdida de cualquier ítem bloquea el flujo de los dos
módulos consumidores y NO es aceptable:

**Modelos Eloquent (NO se tocan):**

- `App\Models\Transcription` — estado, `srt_content`, `duration_seconds`,
  `corrected`, `word_count`, `started_at`, `finished_at`, `error_message`,
  `retries`, `recorded_at`, `discovered_at`, `dispatched_at`.
- `App\Models\TranscriptionSegment` — filas de segmentos con `text`,
  `start_seconds`, `end_seconds`, `source_segment_id`.
- `App\Models\TranscriptionReview` — estado de revisión humana.

**Servicios backend (NO se tocan):**

- `TranscriptionProcessor` — punto de entrada que `TranscriptionPollingService`
  llama cuando un job llega a `done` desde upstream. Crea `TranscriptionSegment`,
  dispara `KeywordMatcher`, `TranscriptionCoherencePass::run()` y persiste
  `state=done`. **Si esto se rompe, ni Correcciones ni Avisos ven contenido nuevo.**
- `TranscriptionPollingService` — polling del upstream para jobs en `queued`.
- `TranscriptionSubmitService` — lógica ffmpeg + POST al upstream. Reutilizada
  por el nuevo worker PG (en lugar de por `ConvertAndTranscribeJob::handle`).
- `TranscriptorApiClient`, `TranscriptorSettings` — sin cambios.

**Invariantes (NO se rompen):**

- Toda transcripción nueva DEBE crearse con `generate_alerts=true` salvo opt-out
  EXPLÍCITO del operador.
- `transcriptions.file_id` UNIQUE — garantía de dedup ya presente.
- Estados válidos: `pending`, `queued`, `processing`, `done`, `error`, `dead`.

## Impact

| Área | Cambio |
|------|--------|
| `ConvertAndTranscribeJob` | **Eliminado.** Lógica de `handle()` movida al worker PG vía `TranscriptionSubmitService`. |
| `LimitTranscriptionConcurrency` | **Eliminado.** Concurrencia = N units supervisord. |
| `ProcessBatchCommand`, `ScanNewRecordingsCommand` | **Eliminados** (ya eran stubs DEPRECATED). |
| `TranscriptionTickCommand` | `evaluateRegulator` sustituye `Redis::llen` por `DB::table('transcriptions')->...count()`. Renombra decision keys (`redis_*` → `pg_*`) y log keys (`current_redis` → `current_pg`). Conserva rampa del regulador. |
| `ScanAndSubmitCommand` | `--no-dispatch` describe ya no-encolar-a-Redis (limpieza). `--dispatch` queda deprecated (default true → el worker PG procesa). |
| `TranscriptionBackfillLostCommand` | `Redis::llen` reemplazado por `COUNT(*)` PG con `recorded_at >= today`. |
| `TranscriptionConfigCommand` | Validación de `queue.connections.redis.retry_after` retirada (sin job Bus). |
| `TranscriptionHealthCheckCommand` | Texto de ayuda actualizado a "worker PG y la API". |
| `TranscriptorSettingsController` | Endpoint `queueDepth` usa `COUNT(*)` PG. |
| `TranscriptorSettings` | `target_redis_queue` → `target_pg_queue`; `dispatch_stagger_ms` retirado. |
| `TranscriptionWebhookController` | Import Redis no usado eliminado. |
| `DiskScannerService` | Limpia referencia a `ConvertAndTranscribeJob::uniqueFor` y `Cache::lock(...::class)`. |
| `ApiTranscriptorController::bulkDispatch` | Body/respuesta idénticos; internamente llama a `TranscriptionBulkDispatchService::dispatch(array $ids)`. |
| `config/queue.php` | Driver `redis` se conserva (otras colas lo usan: `SendAlertDigest`, `MentionsExportJob`, `BackfillKeywordMatches`). El `ConvertAndTranscribeJob::onQueue('transcription')` se elimina con la clase. |
| Units supervisord | Nuevas: `tcloud-transcription-worker-*` ejecutando `transcription:worker --storage=ALL`. Las viejas `tcloud-transcription-batch-*` se mantienen registradas pero deshabilitadas durante 7 días post-cutover. |
| Tablas | Nueva: `transcription_storage_snapshots`. Nuevo índice: `transcriptions_pending_today_dispatch_idx`. Tabla `transcriptions`: DELETE masivo el día del cutover (purga backlog). |

## Rollback

### Freno de emergencia sin deploy

`SystemSetting::set('transcriptor_pg_queue_enabled', '0')` (vía CLI o UI admin)
hace que el tick use el path legacy en menos de 60 segundos (TTL de la cache de
settings). El path legacy = `ConvertAndTranscribeJob::dispatch` a Redis. Workers
`tcloud-transcription-batch-*` re-activables con `supervisorctl start`.

### Rollback completo

1. `SystemSetting::set('transcriptor_pg_queue_enabled', '0')`.
2. Re-activar units supervisord legacy: `supervisorctl start tcloud-transcription-batch-*`.
3. `git revert <commit-del-change>`.
4. `php artisan config:cache`.
5. Verificar que el flujo legacy absorbe las nuevas filas PG. **PELIGRO**: la
   purga de cutover es DESTRUCTIVA. Si se restauró el backup pre-cutover
   (`transcriptions_pre_cutover.csv`), re-importarlo antes de validar.

## Riesgos

1. **Race tick ↔ worker**: con `FOR UPDATE SKIP LOCKED` el worker puede saltarse
   filas tomadas por otro worker. Necesario verificar que el índice
   `transcriptions_pending_dispatchable_idx` cubre `(state, recorded_at) WHERE
   state='pending' AND dispatched_at IS NULL`. **No lo cubre para ORDER BY** —
   por eso se crea `transcriptions_pending_today_dispatch_idx` con `(recorded_at
   DESC, discovered_at DESC)` y mismo WHERE clause.
2. **`/dev/shm` filling**: el worker procesa lotes grandes en serie. Si el host se
   queda sin SHM, `TranscriptionSubmitService::markRequeueable` ya reencola la
   fila a `state='pending'` con `requeue_after_at`. Verificar que el watchdog
   distingue "reencolada intencionalmente" vs "processing zombie" (criterio:
   `submission_committed_at IS NULL AND state='processing' AND
   updated_at < now() - processing_timeout_seconds`).
3. **Watchdog loop**: si el watchdog es demasiado agresivo, puede re-encolar
   filas que están siendo procesadas legítimamente. Default conservador:
   timeout 900s (mayor que `ConvertAndTranscribeJob::$timeout = 600s` original).
4. **Renombre `target_redis_queue`**: el setting está en `system_settings`.
   Cualquier cron externo o script que lo lea directamente debe actualizarse.
   **Verificado**: solo `TranscriptorSettings` lo lee (helper centralizado).
5. **Eliminación de `ConvertAndTranscribeJob`**: hay 9 archivos que lo
   referencian. **Verificado**: todos están en el scope del transcriptor. No
   hay consumidores externos (otros módulos no transcriben).
6. **Snapshot retention**: 7 días × 96 snapshots × ~70 storages ≈ 470k filas.
   Bajo pero no trivial. Índice `(storage_provider_id, captured_at DESC)` para
   que la lectura desde el tab Storages sea O(1). Job nocturno
   `transcriptor:prune-storage-snapshots --days=7`.
7. **`LimitTranscriptionConcurrency` retirado**: el control de concurrencia se
   confía a N units supervisord. **Tradeoff aceptado**: cada worker hace 1 ffmpeg
   a la vez; paralelismo = escalar units, no flag. Más explícito y testeable
   que el funnel Redis.

## Open Questions / TODO antes de implementar

1. **UI del snapshot en tab Storages**: ¿card simple ("Pendientes: 87 (+12 vs 15
   min)") o tabla histórica con sparkline de 24h?
2. **Watchdog cadence**: ¿integrado al tick (corre cada 2 min) o cron aparte
   (`transcriptor:watchdog-processing`)? Recomiendo cron aparte porque el tick
   es planner puro y la cadencia del watchdog debe ser independiente.
3. **Harnesses de regresión**: ¿qué cubrimos? Sugerencia:
   - `harness_transcriptor_pg_queue.php`: depth counter PG, FOR UPDATE SKIP LOCKED,
     watchdog re-encolamiento, regulator signals.
   - `harness_transcriptor_storage_snapshots.php`: agregaciones por storage,
     retention, lectura desde tab.
4. **Verificación pre-implementación**: `grep -rn 'ConvertAndTranscribeJob\|LimitTranscriptionConcurrency\|target_redis_queue\|dispatch_stagger_ms' /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/openspec`
   debe devolver **0 referencias** post-implementación (excluyendo este proposal
   y el archivo `.archive` si lo hubiera).

## Decisiones tomadas durante la exploración (2026-09-15)

- Cola en PostgreSQL = tabla `transcriptions` misma, no tabla nueva.
- Worker como proceso separado (W2) supervisado por systemd.
- Scope del worker: `recorded_at >= today` (no `discovered_at`).
- Orden del despacho: `ORDER BY recorded_at DESC, discovered_at DESC`.
- Purga de cutover: DELETE físico (no `state='dead'`).
- Regulador: misma lógica, fuente local = COUNT PG.
- Naming PG-only: `target_pg_queue`, `pg_queue_depth`, `current_pg*`. Cero
  mención de Redis en código transcriptor post-migración.
- Concurrencia: N units supervisord. No funnel Redis.
- Stagger: se retira `dispatch_stagger_ms` sin reemplazo (regulador + SHM
  guardrail existentes).
- Snapshot schema: incluye `remote_queue_queued` para evitar HTTP en cada render.
- Watchdog: cron separado, timeout 900s, re-encolamiento a `state='pending'`.
