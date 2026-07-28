# Spec — Transcription Orchestrator Runtime

> Single source of truth for the runtime behavior of the transcription module: every-2-minutes scan + regulator-bounded dispatch, current-day only.

## ADDED Requirements

### 1. Unified Tick Phase

- The system **SHALL** invoke `transcription:tick` every minute via Laravel Scheduler, and the command **SHALL** self-throttle against `tick_interval_minutes` (default `2`) using the cache key `transcriptor:tick:last_run`.
  - The cadence is therefore tunable at runtime without editing `routes/console.php` or reloading cron.
- Each invocation **MUST** complete both phases within `withoutOverlapping(150)` seconds.
- The tick **MUST** be the only scheduled entry point for transcription work. There is no separate "discovery" or "enqueue" schedule; both phases live inside `TranscriptionTickCommand::handle()`.

### 2. Phase 1 — Discovery (current-day only)

- The tick **SHALL** invoke `transcription:scan-and-submit --no-dispatch` programmatically in Phase 1.
- The scanner **MUST** only consider files whose `mtime` falls within `now()->startOfDay()` to `now()`. This is enforced by `--days=0` in `ScanAndSubmitCommand` and verified by reading L24.
- The discovery phase **MUST NOT** push jobs to the Redis queue `queues:transcription`. Its sole responsibility is to keep the `transcriptions` table populated with `state=pending, job_id=null` rows for newly-discovered media files of the current day.

### 3. Phase 2 — Regulator dispatch (current-day only)

- After Phase 1, the tick **MUST** compute its dispatch batch via the regulator formula:
  ```
  target = (int) config('transcriptor.target_redis_queue')          # default 140
  min    = (int) config('transcriptor.min_batch')                   # default 10
  max    = (int) config('transcriptor.max_batch')                   # default 200
  current = (int) Redis::llen('queues:transcription')
  batch = max($min, min($max, $target - $current + 5));
  ```
- If the regulator computes `batch <= 0`, the tick **SHALL** log "queue at target, skip dispatch" and return `Command::SUCCESS` **without** touching Redis.
- Otherwise, the tick **SHALL** dispatch `ConvertAndTranscribeJob` for up to `batch` rows where:
  ```
  state = 'pending' AND job_id IS NULL AND created_at >= now()->startOfDay()
  ORDER BY created_at ASC
  ```
  ordered oldest-first within the day for fairness.
- The dispatch **MUST** use the existing `ConvertAndTranscribeJob::dispatch($fileId, true)` producer; no new job class is introduced.

### 4. Same-day scope — explicit non-goal

- The tick **SHALL NOT** dispatch files whose `created_at` predates `now()->startOfDay()`.
- Previous-day pending rows **MUST** remain queryable via `transcriptor:diagnose-pending` and re-dispatchable via the existing `POST /api-transcriptor/jobs/bulk-dispatch` UI endpoint.
- This scope is encoded as `TRANSCRIPTOR_SCOPE=current_day` in `.env`. Future expansion to `current_day_plus_one` or `unbounded` is permitted by changing that env, but **MUST** be documented in a new OpenSpec change before touching code.

### 5. Polling Phase (independent of the tick)

- The system **SHALL** run `transcription:poll-results` every minute via Laravel Scheduler, independent of the tick cadence.
- This phase is **NOT** merged into the tick. Polling concerns (re-dispatch stuck rows, fetch SRT) are separate from discovery/dispatch.

### 6. Auto-tuner Phase

- The system **SHALL** run `transcription:tune --apply` every 5 minutes via Laravel Scheduler.
- The tuner **MUST**:
  - Count `StorageProvider::transcriptionEnabled()` rows split by `folder_layout` (`flat` vs `grouped_by_subfolder`).
  - For grouped storages, count the **immediate subdirectories** of `base_path` and add to the mean count.
  - Compute `workers = clamp(medios_total / worker_ratio, worker_min, worker_max)`, con los tres limites leidos de `TranscriptorSettings` (defaults `6`, `3`, `12` — los antiguos `private const`).
  - Acotar el `worker_max` efectivo al numero de units `tcloud-transcription-batch-*.service` realmente instaladas, y exponer ese tope a la API de settings para que la UI no pueda pedir workers inexistentes.
  - Con `worker_override` distinto de 0, usarlo tal cual en lugar de la formula. Es la palanca directa del operador durante una saturacion.
  - `systemctl enable --now` exactly that number of `tcloud-transcription-batch-N.service` units, and `systemctl stop` the rest.
- The tuner **MUST** be idempotent: a back-to-back run with the same storage count must NOT issue start/stop shell calls for already-correctly-stated services.
- The tuner **MUST** run `reconcileForbiddenPools()` on every `--apply`: enumerar instancias activas de `tcloud-transcription-worker@*.service`, hacer `systemctl disable --now` de cada una, reportarlas bajo `stopped_orphans[]` y emitir `Log::warning`.
  - §7 ya las prohibia pero nada lo hacia cumplir. Verificado el 2026-07-26: 10 instancias `worker@` activas junto a 11 `batch-N`, es decir **21 workers reales** frente a los 11 que el tuner creia gestionar, alimentando una API con 2 workers GPU. El contrato pasa de declarativo a autoaplicado.
- The tuner **SHALL** emit a JSON line per run to `storage/logs/transcription-tune.log` containing `{ts, storages_total, medios_total, workers_target, started[], stopped[], stopped_orphans[], worker_min, worker_max, worker_ratio, worker_override, units_installed}`.

### 7. Worker Pool Contract

- The system's only worker pool is the set of systemd services `tcloud-transcription-batch-{1..12}.service`, all of which:
  - `ExecStart=/usr/bin/php -d memory_limit=512M artisan queue:work --queue=transcription --tries=3 --timeout=600 --sleep=1`
  - `Restart=always`, `RestartSec=5`
  - Logs appended to `storage/logs/worker-batch-N.log`.
- **No other worker pool** is permitted: no `nohup`-ed manual workers, no `supervisord`-managed workers, no additional systemd templates. Any future pool must add itself as a new capability spec.
- **`tcloud-transcription-batch.service` (legacy singular)** **SHALL** remain `inactive` and its template-style units (`tcloud-transcription-worker@.service`) **SHALL NOT** have enabled instances.
- Este contrato **MUST** hacerse cumplir automaticamente por `transcription:tune --apply` (§6), no por convencion.
- `queue.connections.redis.retry_after` **MUST** superar `ConvertAndTranscribeJob::$timeout`. Default elevado de `90` a `900`.
  - Con `retry_after=90` y `$timeout=600`, Redis devolvia a la cola todo job de mas de 90s mientras el worker original seguia en ffmpeg, produciendo dos ffmpeg y dos POST del mismo archivo. La guarda de idempotencia por `job_id` no cubre esa ventana: solo actua una vez la primera copia ha escrito `job_id`. `transcription:config` valida la relacion y falla ruidosamente si se rompe.

### 8. Single OS Cron, No Transcription Entries

- `/var/spool/cron/crontabs/root` **MUST** contain exactly:
  - One `* * * * *` for `schedule:run` (the Laravel scheduler entry point).
- **No entry** for `transcription_enqueue_batch.php` (the script is manual-only or absorbed into `transcription:tick`).
- `/www/server/cron/*` (panel `aaPanel` scripts) **MUST NOT** invoke `transcription_enqueue_batch.php` directly. If any is found, it is a regression and **MUST** be removed.

### 9. Configuration Surface

- La configuracion de runtime **SHALL** resolverse en `App\Services\Ia\TranscriptorSettings` con esta precedencia:
  1. Fila en `system_settings` con clave `transcriptor.<key>`
  2. `config("transcriptor.<key>")` (capa env, como hasta ahora)
  3. Default del esquema
- Los valores **MUST** acotarse a `min`/`max` **tanto en lectura como en escritura**, para que una fila corrupta no pueda producir un valor invalido en runtime.
- El servicio **MUST** refrescar su mapa por TTL (cache 60s, memo en proceso 30s) en vez de memoizar una sola vez: los procesos `queue:work` viven horas y una lectura en constructor congelaria la configuracion hasta reiniciar systemd.
- Ningun ServiceProvider **SHALL** volcar los valores de BD sobre el repositorio de config: añadiria un hit a BD en cada boot, rompería `artisan` con la base caida, y ocultaria el origen del valor.
- Borrar una fila `transcriptor.*` **SHALL** restaurar el valor de `config/transcriptor.php`. Esa distincion de tres estados (`bd` / `env` / `archivo`) **MUST** reportarse en `effective()` y mostrarse en la UI.
- Claves nuevas sobre las ya listadas: `dispatch_paused`, `tick_interval_minutes`, `dispatch_stagger_ms`, `inflight_max`, `scan_max_dispatch_per_cycle`, `stale_resend_limit`, `poll_limit`, `submit_max_attempts`, `submit_retry_base_ms`, `worker_min`, `worker_max`, `worker_ratio`, `worker_override`, `ui_max_parallel_sends`, `ui_batch_max`, `corrections_chunk` (existia y no la leia nadie), `scan_days_back` (idem).
- `callback_host` se mapea a `TRANSCRIPTOR_CALLBACK_HOST` **solo para mostrar** — la vista la pintaba vacia. La prohibicion de cablear una ruta de webhook `TRANSCRIPTOR_WEBHOOK_*` **sigue vigente**.

### 10. Semaforo de concurrencia

- El sistema **SHALL** proveer un job middleware que acote la **ejecucion concurrente de ffmpeg + POST**, independiente del numero de workers, via `Redis::funnel('transcriptor:inflight')->limit(inflight_max)->releaseAfter(650)`.
- Con `inflight_max <= 0` el middleware **MUST** dejar pasar sin coste (desactivado por defecto).
- `releaseAfter` **MUST** superar el `$timeout` del job para que un worker muerto libere su cupo en vez de retenerlo.
- `ConvertAndTranscribeJob` **MUST** usar `$tries = 0` mas `retryUntil(now()->addHours(2))`. `release()` incrementa `attempts()`, asi que el anterior `$tries = 1` haria fallar de forma permanente al primer job que el semaforo bloqueara.
- Motivo: el objetivo de cola regula el **ritmo de encolado**, no la **concurrencia real**. La evidencia del 2026-07-24 11:35:32-36 (15 `ffmpeg falló` en 4 segundos, stderr truncado a offsets distintos, cero HTTP 429) es contencion local de CPU/IO, no rechazo de la API.

### 11. Prevencion de despacho duplicado

- `ConvertAndTranscribeJob` **MUST** implementar `ShouldBeUnique` con `uniqueId() = fileId` y `uniqueFor = 900` (pareado con `retry_after`).
- El mismo `file_id` llega a `queues:transcription` por cuatro caminos: el tick, `scan-and-submit`, `bulk-dispatch` y el envio manual.

### 12. Encolado manual acotado

- `ScanAndSubmitCommand` **MUST NOT** calcular su tope como `scan_batch x numero_de_storages`.
  - Con 31 storages y `scan_batch=100` eso eran 3100 jobs en un bucle apretado sin pasar por el regulador. Es ademas la ruta del boton "Escanear storages" de la UI, asi que tambien inundaba con disparo manual.
- El tope **MUST** ser `min(scan_max_dispatch_per_cycle, computeDispatchBatch(Redis::llen('queues:transcription')))`.
- El envio multiple del navegador **MUST** acotar sus peticiones paralelas a `ui_max_parallel_sends`, y `POST /ia/api-transcriptor/transcribe/{fileId}` **MUST** llevar `throttle` como defensa en profundidad. Ese endpoint corre ffmpeg + POST sincronos dentro de php-fpm.

### 13. Superficie de observacion y control

- El sistema **SHALL** exponer, bajo el grupo de rutas existente `['auth','admin']` + `prefix('ia')`:
  - `GET  /ia/api-transcriptor/settings` — valores efectivos con su origen, mas contexto en vivo (profundidad de cola vs objetivo, workers activos y huerfanos, conteos por estado, y el lote que el regulador calcularia ahora mismo)
  - `POST /ia/api-transcriptor/settings` y `POST /ia/api-transcriptor/settings/reset`
  - `POST /ia/api-transcriptor/settings/run-tick` — ejecutar o simular la tarea programada bajo demanda
- Las escrituras **MUST** validarse desde la misma constante de esquema que renderiza el formulario, y **MUST** registrarse en log con el id del admin tomado de `session('user')` (este proyecto usa auth por sesion, nunca `auth()->user()`).
- Invariantes cruzadas **MUST** aplicarse en escritura sobre el estado RESULTANTE: `min_batch <= max_batch`, `worker_min <= worker_max`, `runway <= target_redis_queue`, `submit_timeout < ConvertAndTranscribeJob::$timeout`.
- Un comando `transcription:config {--json} {--set=*} {--reset=*}` **SHALL** ofrecer la misma superficie por CLI, como salida de emergencia cuando la UI no este disponible.

## MODIFIED Requirements

None. This change only documents existing operational behavior.

## REMOVED Requirements

None. No existing capability is dropped.

---

## Acceptance Criteria

After this change is in production for 30 minutes with steady traffic (~20 files/min):

1. `Redis::llen('queues:transcription')` oscillates in `[120, 160]` consistently.
2. `/api/stats` on the transcriptor reports `jobs.queued` in `[10, 32]` consistently.
3. The schedule's `transcription:tune` log shows `workers_target = 11` (matching the 66-medios equivalent formula) without churn on stable inputs.
4. `systemctl list-units --all tcloud-transcription*` shows exactly 11 `active` `tcloud-transcription-batch-N.service` units and 0 others.
5. `/var/log/syslog` shows NO invocation of `transcription_enqueue_batch.php` (the script is no longer scheduled). The only transcription-related entries are `php artisan schedule:run` running every minute.
6. No job is dispatched directly to `queues:transcription` from anywhere except `transcription:tick`. A `redis-cli MONITOR` run over 10 minutes **MUST NOT** show `LPUSH queues:transcription` from any source other than that command.
7. `Transcription` rows dispatched by `transcription:tick` are limited to `created_at >= now()->startOfDay()`. A previous-day stale pending row remains in the table but **MUST NOT** appear in dispatched IDs over a 30-minute observation.

### Criterios añadidos por la regulacion en caliente (2026-07-26)

8. Con la cola por encima del objetivo, `transcription:tick --dry-run` imprime `DISPATCH: skip`. Antes imprimia `batch_computed=10` y seguia encolando. Cubierto ademas por `tests/Unit/TranscriptorSettingsTest.php` sobre `computeDispatchBatch()`.
9. `systemctl list-units 'tcloud-transcription-*' --all` muestra exactamente `workers_target` units `tcloud-transcription-batch-N` activas y **cero** instancias `tcloud-transcription-worker@N` — hecho cumplir automaticamente por el tuner, no por convencion.
10. Dos corridas seguidas de `transcription:tune --apply --json` emiten `started`, `stopped` y `stopped_orphans` vacios.
11. Cambiar `target_redis_queue` desde la UI se refleja en el contexto del **siguiente tick programado**, sin reiniciar systemd. Esto prueba que el proceso CLI de larga vida observa el cambio.
12. Con `dispatch_paused` activo, el tick registra descubrimiento-completado + dispatch-omitido, y los endpoints de envio de la UI devuelven HTTP 423.
13. Con `inflight_max=2` y 11 workers, `ps aux | grep -c ffmpeg` nunca supera 2 mientras drena un backlog de 50 jobs, y ningun job agota `retryUntil`.
14. Despachar el mismo `file_id` por dos caminos en menos de 900s hace crecer `queues:transcription` en 1, no en 2.
15. `transcription:scan-and-submit` sin `--no-dispatch` hace crecer la cola como maximo en `scan_max_dispatch_per_cycle`, no en `scan_batch x numero_de_storages`.
16. Seleccionar 50 archivos en el envio multiple de la UI mantiene los ffmpeg concurrentes en torno a `ui_max_parallel_sends`, no en 50.
17. `DELETE FROM system_settings WHERE key LIKE 'transcriptor.%'` restaura exactamente el comportamiento previo al cambio.

> **Requisito de despliegue**: las Fases 1 y 5 solo surten efecto tras `systemctl restart 'tcloud-transcription-batch-*'`. Los workers son procesos de larga vida que mantienen en memoria tanto la config de colas (`retry_after`) como la definicion de `ConvertAndTranscribeJob` (middleware, `ShouldBeUnique`, `$tries`). Desplegar sin reiniciar deja el despachador y los workers en versiones distintas de la clase.
