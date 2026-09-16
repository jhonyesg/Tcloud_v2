<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envia una Transcription pendiente al transcriptor externo.
 *
 * transcriptor-two-phase-staging: el camino de envio se parte en DOS fases
 * desacopladas, que es lo que permite mantener sano el ecosistema local+remoto:
 *
 *   FASE 1 - stage()   (comando transcription:stage, en goteo)
 *     ffmpeg convierte el audio de origen al WAV/opus de envio y lo deja en
 *     /dev/shm. Marca `staged_path`/`staged_bytes`/`staged_at`. NO toca la red.
 *     El ritmo lo controla `staging_pace_seconds` y el inventario
 *     `staging_target_inventory`, asi el host local no sufre picos de CPU.
 *
 *   FASE 2 - send()    (worker PG, bajo headroom remoto)
 *     Si la fila tiene `staged_path` valido, hace el POST del archivo YA
 *     convertido (barato: no hay ffmpeg) y borra el staged. El techo lo pone
 *     `target_remote_queue` leido de /api/metrics/overview.
 *
 * `submit()` se conserva como camino sincrono (stage+send en un paso) para los
 * callers que no pueden esperar al stager: bulk-dispatch manual, backfill y
 * dispatch-now. Con `staging_enabled=true` el worker prefiere el camino staged.
 *
 * No envia callback_url: la recepcion del SRT se hace por polling
 * (TranscriptionPollingService).
 */
class TranscriptionSubmitService
{
    private ?string $cachedNodeId = null;

    public function __construct(
        private AudioConverter $converter,
        private TranscriptorApiClient $client,
        private TranscriptorSettings $settings,
    ) {}

    /**
     * Camino sincrono: convierte y envia en un solo paso.
     *
     * Lo usan los flujos donde el operador espera el resultado inmediato
     * (bulk-dispatch manual, dispatch-now, backfill). Si ya existe un staged
     * valido, lo reutiliza y se salta ffmpeg.
     */
    public function submit(Transcription $transcription): array
    {
        $staged = $this->validStagedPath($transcription);

        if ($staged === null) {
            // requireClaim=false: este proceso YA es dueño de la fila (el
            // worker la reclamo con FOR UPDATE y la paso a processing). El
            // guard de claim solo aplica al stager, que convierte filas ajenas
            // sin haberlas reclamado.
            $stage = $this->stage($transcription, requireClaim: false);
            if (!($stage['ok'] ?? false)) {
                return $stage;
            }
            $staged = $stage['staged_path'] ?? null;
        }

        return $this->send($transcription, $staged);
    }

    /**
     * FASE 1 — convierte el audio de origen y lo deja listo en tmpfs.
     *
     * No hace red. Idempotente: si ya hay un staged valido devuelve ok sin
     * re-convertir. Respeta el presupuesto del ramdisk y los filtros de
     * tamano/legibilidad del audio de origen.
     *
     * @param  bool  $requireClaim  true cuando el llamador NO posee la fila (el
     *                              stager en cron). En ese caso la publicacion
     *                              del staged exige que la fila siga siendo
     *                              candidata, porque un worker pudo reclamarla
     *                              durante los minutos de ffmpeg. El camino
     *                              sincrono (submit, desde el propio worker)
     *                              pasa false: el dueño es el mismo proceso.
     * @return array{ok:bool, staged_path?:?string, staged_bytes?:int, error?:string, requeueable?:bool, lost_claim?:bool}
     */
    public function stage(Transcription $transcription, bool $requireClaim = true): array
    {
        $file = $transcription->file;
        if (!$file) {
            $this->markError($transcription, 'Archivo asociado no existe');

            return ['ok' => false, 'error' => 'Archivo no existe'];
        }

        $existing = $this->validStagedPath($transcription);
        if ($existing !== null) {
            return [
                'ok' => true,
                'staged_path' => $existing,
                'staged_bytes' => (int) $transcription->staged_bytes,
            ];
        }

        $storage = $file->storageProvider;
        if (!$storage) {
            $this->markError($transcription, 'Storage provider no existe');

            return ['ok' => false, 'error' => 'Storage no existe'];
        }

        $srcPath = rtrim((string) $storage->base_path, '/') . '/' . ltrim((string) $file->path, '/');
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            $this->markError($transcription, "Archivo no legible en disco: {$srcPath}");

            return ['ok' => false, 'error' => 'Archivo no legible'];
        }

        // Filtro de tamano: archivos < N bytes no pasan por ffmpeg.
        // Caso tipico: el grabador de radio crea el MP3 al iniciar y el tick lo
        // dispatcha mientras aun se esta escribiendo (0 bytes). Si el stream de
        // red se congela, el archivo queda en 0 bytes durante minutos; marcarlo
        // dead irreversiblemente perdia la transcripcion cuando el stream se
        // descongelaba y el archivo crecia. Ahora se reencola (requeue_after_at)
        // para que el tick lo reintente cuando el archivo haya crecido, con tope
        // de max_retries para no encolar basura corrupta para siempre.
        $minSize = $this->settings->int('min_file_size_bytes');
        if ($minSize > 0) {
            $size = @filesize($srcPath);
            if ($size !== false && $size < $minSize) {
                $maxRetries = $this->settings->int('max_retries');
                if ((int) $transcription->retries < $maxRetries) {
                    $transcription->update(['retries' => (int) $transcription->retries + 1]);
                    $this->markRequeueable($transcription, "Archivo incompleto ({$size} bytes < {$minSize} minimo). Reintento en el proximo ciclo.");

                    return ['ok' => false, 'error' => "Archivo < {$minSize} bytes", 'requeueable' => true];
                }
                $this->markDead($transcription, "Archivo descartado por tamano ({$size} bytes < {$minSize} minimo). Probable archivo truncado por grabador en vivo.");

                return ['ok' => false, 'error' => "Archivo < {$minSize} bytes"];
            }
        }

        $tmpDir = $this->tmpDir();

        // Pre-flight de tmpfs: si no hay espacio para el minimo configurado, NO
        // se invoca ffmpeg. El job se reencola para el siguiente ciclo.
        // Sin esta guarda, ffmpeg falla en ~250ms con "Could not seek: Invalid
        // argument" y el job pasa a error/dead tras max_retries.
        $minShmFree = $this->settings->int('min_shm_free_bytes');
        $freeBytes  = @disk_free_space($tmpDir);
        if ($freeBytes !== false && $freeBytes < $minShmFree) {
            $msg = "tmpfs sin espacio: {$freeBytes} bytes libres, mínimo {$minShmFree}. "
                 . 'Job reencolado para próximo intento (revisar /dev/shm).';
            Log::warning("TranscriptionSubmitService: {$msg}", [
                'tmp_dir' => $tmpDir,
                'free_bytes' => $freeBytes,
                'min_shm_free_bytes' => $minShmFree,
                'file_id' => $file->id,
            ]);
            $this->markRequeueable($transcription, $msg);

            return ['ok' => false, 'error' => $msg, 'requeueable' => true];
        }

        $format = $this->settings->str('audio_output_format');
        $tmpPath = $this->buildStagedPath($tmpDir, $file->name, $format);

        try {
            $this->converter->convert($srcPath, $tmpPath, $format);

            $bytes = (int) (@filesize($tmpPath) ?: 0);

            // Claim condicional: solo se publica el staged si la fila sigue
            // siendo candidata (pending, sin reclamar por un worker, sin staged
            // previo).
            //
            // Esto cierra una condicion de carrera real medida el 2026-09-15:
            // el stager lee candidatos, empieza a convertir (ffmpeg tarda
            // minutos y no bloquea la fila), y en ese hueco el worker PG la
            // reclama y la ENVIA por el camino sincrono. Al terminar la
            // conversion, el stager escribia `staged_path` sobre una fila ya
            // `queued`: el WAV quedaba en tmpfs para siempre y nadie lo enviaba
            // (41 archivos / 1.6 GB filtrados). Con este guard el UPDATE afecta
            // 0 filas, el archivo recien convertido se borra y no queda basura.
            $claimed = DB::table('transcriptions')
                ->where('id', $transcription->id)
                ->when($requireClaim, function ($q) {
                    $q->where('state', Transcription::STATE_PENDING)
                      ->whereNull('dispatched_at')
                      ->whereNull('staged_path');
                })
                ->update([
                    'staged_path' => $tmpPath,
                    'staged_bytes' => $bytes,
                    'staged_at' => now(),
                    'error_message' => null,
                ]);

            if ($claimed === 0) {
                // La fila ya no nos pertenece (worker la reclamo o alguien mas
                // la convirtio). Descartar el trabajo y salir en limpio.
                @unlink($tmpPath);
                Log::info('TranscriptionSubmitService::stage descartado por claim perdido', [
                    'tx_id' => $transcription->id,
                    'file_id' => $file->id,
                ]);

                return ['ok' => true, 'staged_path' => null, 'staged_bytes' => 0, 'lost_claim' => true];
            }

            $transcription->forceFill([
                'staged_path' => $tmpPath,
                'staged_bytes' => $bytes,
                'staged_at' => now(),
                'error_message' => null,
            ])->syncOriginal();

            return ['ok' => true, 'staged_path' => $tmpPath, 'staged_bytes' => $bytes];
        } catch (\Throwable $e) {
            // Un fallo de conversion SI es un intento fallido: cuenta retries
            // (archivo corrupto, sin pista de audio, ffmpeg ausente).
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            $this->markError($transcription, 'ffmpeg: ' . $e->getMessage());
            Log::error("TranscriptionSubmitService::stage file {$file->id}: {$e->getMessage()}");

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * FASE 2 — hace el POST del audio ya convertido y libera el staged.
     *
     * El techo lo pone la cola remota (`target_remote_queue` leido de
     * /api/metrics/overview). Si no hay headroom, la fila se reencola
     * conservando su staged para el siguiente intento: NO se reconvierte.
     *
     * @return array{ok:bool, job_id?:string, state?:string, error?:string, requeueable?:bool, retry_after?:int}
     */
    public function send(Transcription $transcription, ?string $stagedPath = null): array
    {
        $file = $transcription->file;
        if (!$file) {
            $this->markError($transcription, 'Archivo asociado no existe');

            return ['ok' => false, 'error' => 'Archivo no existe'];
        }

        $stagedPath = $stagedPath ?? $this->validStagedPath($transcription);

        // Sin staged utilizable el sender no puede operar: la fila vuelve a la
        // cola de conversion. No es error de envio, es un aplazamiento.
        if ($stagedPath === null) {
            $this->clearStaged($transcription);
            $this->markRequeueable($transcription, 'Sin audio staged: vuelve a la cola de conversión.');

            return ['ok' => false, 'error' => 'Sin audio staged', 'requeueable' => true];
        }

        // TTL del staged: si un paro prolongado de la API dejo el WAV horas en
        // tmpfs, se descarta y la fila vuelve a conversion en vez de enviar un
        // archivo obsoleto (y de seguir ocupando el presupuesto del ramdisk).
        $ttlMinutes = $this->settings->int('staging_ttl_minutes');
        if ($ttlMinutes > 0 && $transcription->staged_at !== null
            && $transcription->staged_at->lt(now()->subMinutes($ttlMinutes))) {
            $this->discardStaged($transcription);
            $this->markRequeueable($transcription, "Audio staged excedió TTL ({$ttlMinutes} min): se reconvertirá.");

            return ['ok' => false, 'error' => 'Staged expirado', 'requeueable' => true];
        }

        // Pre-flight: respetar el freno con histéresis de la cola upstream.
        // Esta guarda aplica a TODAS las rutas de despacho (worker PG + burst
        // dispatcher + bulk) y garantiza que cuando la API remota este saturada
        // NO se haga POST, evitando acumular mas trabajo del que la GPU puede
        // absorber. Reusa la cache del regulador.
        //
        // Antes comparaba `queue_queued >= target_remote_queue` en crudo, lo que
        // producia ping-pong alrededor del techo (frena en 180, drena 1 y
        // reanuda en 179). Ahora usa RemoteQueueBrake: frena al llegar al techo
        // y no reanuda hasta que la cola baje a `resume_remote_queue` (default
        // 120), revalidando cada `remote_queue_recheck_seconds` (default 30 s).
        // Es el MISMO freno que decide el planner, asi que ambos coinciden.
        $remoteInfo = Cache::remember(
            'transcriptor:remote_stats:info',
            max(1, $this->settings->int('regulator_remote_cache_seconds')),
            fn () => $this->client->getRemoteInfo()
        );
        $brake = app(RemoteQueueBrake::class)->evaluate($remoteInfo, $this->settings);
        if ($brake['braked'] ?? false) {
            $msg = "Cola remota frenada (histéresis): queue={$brake['queue']} "
                 . "target={$brake['target']} resume={$brake['resume']}. "
                 . 'Job reencolado para próximo ciclo.';
            Log::info("TranscriptionSubmitService: {$msg}", [
                'file_id' => $file->id,
                'remote_queue_queued' => $brake['queue'],
                'target_remote_queue' => $brake['target'],
                'resume_remote_queue' => $brake['resume'],
                'reason' => $brake['reason'],
            ]);
            // El staged se CONSERVA: reconvertir seria quemar CPU por un freno
            // de red. La fila solo espera headroom. El aplazamiento es corto
            // (remote_queue_requeue_seconds, default 30 s) para que el sender
            // revalide la cola junto con la ventana del brake y no quede
            // esperando los 5 min genericos del requeue_after_minutes.
            $this->markRequeueable(
                $transcription,
                $msg,
                keepStaged: true,
                requeueSeconds: max(5, $this->settings->int('remote_queue_requeue_seconds')),
            );

            return ['ok' => false, 'error' => $msg, 'requeueable' => true];
        }

        try {
            // POST /v1/transcribe sin callback_url (recibimos por polling).
            $data = $this->client->submitNoCallback($file, $stagedPath);

            $transcription->update([
                'job_id' => $data['job_id'] ?? null,
                'node_url' => $this->client->getBaseUrl(),
                'node_id' => $data['node_id'] ?? $this->resolveNodeId(),
                'state' => $data['state'] ?? Transcription::STATE_QUEUED,
                'original_name' => $file->name,
                'error_message' => null,
                // Marca del envio EFECTIVO. Antes solo se ponia al crear la
                // fila, asi que en un reenvio (backfill, retry, reproceso)
                // quedaba congelada en la fecha original. El corte por
                // antiguedad del poller la usa para decidir si un job lleva
                // demasiado tiempo sin resolverse.
                'started_at' => now(),
                'last_polled_at' => null,
                // Momento exacto en que la API externa acuso recibo del job.
                // Es la frontera entre "nuestro trabajo" (cola, ffmpeg, POST)
                // y "trabajo de la GPU remota" (latencia del transcriptor).
                'submission_committed_at' => now(),
            ]);

            // El staged ya viajo: liberar el tmpfs. Se hace DESPUES del update
            // para que un fallo de disco no borre el audio antes de tener job_id.
            $this->discardStaged($transcription);

            return ['ok' => true, 'job_id' => $transcription->job_id, 'state' => $transcription->state];
        } catch (UpstreamRateLimitException $e) {
            // 429: respetar Retry-After. No marcar error/dead; el tick reintentara.
            $reason = "Rate limit upstream (retry_after={$e->retryAfter()}s)";
            $this->markRequeueable($transcription, $reason, keepStaged: true);
            Log::warning("TranscriptionSubmitService: 429 file={$file->id} retry_after={$e->retryAfter()}s");

            return ['ok' => false, 'error' => $reason, 'requeueable' => true, 'retry_after' => $e->retryAfter()];
        } catch (UpstreamUnavailableException $e) {
            // 503: respetar Retry-After (o fallback). Strike al circuit breaker.
            $reason = "Upstream unavailable (retry_after={$e->retryAfter()}s)";
            $this->markRequeueable($transcription, $reason, keepStaged: true);
            try {
                app(UpstreamCircuitBreaker::class)->recordStrike();
            } catch (\Throwable $ignored) {}
            Log::warning("TranscriptionSubmitService: 503 file={$file->id} retry_after={$e->retryAfter()}s");

            return ['ok' => false, 'error' => $reason, 'requeueable' => true, 'retry_after' => $e->retryAfter()];
        } catch (\Throwable $e) {
            $this->markError($transcription, $e->getMessage());
            Log::error("TranscriptionSubmitService: file {$file->id}: {$e->getMessage()}");

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ruta valida del staged de una fila, o null si no hay o ya no existe.
     * Un staged borrado por fuera (purga manual, reboot del tmpfs) no debe
     * hacer fallar el envio: se trata como "sin staged".
     */
    private function validStagedPath(Transcription $t): ?string
    {
        $path = $t->staged_path;
        if (!is_string($path) || $path === '') {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            if ($t->staged_path !== null) {
                Log::warning('TranscriptionSubmitService: staged_path inexistente, se limpiara', [
                    'tx_id' => $t->id,
                    'staged_path' => $path,
                ]);
                $this->clearStaged($t);
            }

            return null;
        }

        return $path;
    }

    private function buildStagedPath(string $tmpDir, string $fileName, string $format): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName);
        if ($safeBase === '') {
            $safeBase = 'audio';
        }

        return $tmpDir . '/' . $safeBase . '_' . substr(md5(uniqid('', true)), 0, 6)
            . '.' . AudioConverter::extensionFor($format);
    }

    private function tmpDir(): string
    {
        $tmpBase = '/dev/shm';
        if (!is_dir($tmpBase) || !is_writable($tmpBase)) {
            $tmpBase = sys_get_temp_dir();
            Log::warning("TranscriptionSubmitService: /dev/shm no escribible, fallback a {$tmpBase}");
        }
        $tmpDir = $tmpBase . '/tcloud-transcription';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        @chmod($tmpDir, 0777);

        return $tmpDir;
    }

    /**
     * Borra el archivo staged y limpia los punteros en BD. No toca el estado
     * ni los retries: es solo higiene del tmpfs.
     */
    private function discardStaged(Transcription $t): void
    {
        $path = $t->staged_path;
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
        $this->clearStaged($t);
    }

    private function clearStaged(Transcription $t): void
    {
        if ($t->staged_path === null && $t->staged_bytes === null && $t->staged_at === null) {
            return;
        }
        // updateQuietly: la limpieza del puntero no debe disparar observers ni
        // tocar updated_at como si fuera un cambio de negocio.
        $t->forceFill(['staged_path' => null, 'staged_bytes' => null, 'staged_at' => null])->saveQuietly();
    }

    /**
     * Bytes totales ocupados hoy en tmpfs por el staging. Lo usa el comando
     * `transcription:stage` para respetar `staging_budget_bytes` y la UI para
     * mostrar el inventario real.
     */
    public function stagedInventory(): array
    {
        try {
            $today = BogotaTime::todayStart();
            $rows = DB::table('transcriptions')
                ->whereNotNull('staged_path')
                ->where('staged_at', '>=', $today)
                ->selectRaw('COUNT(*) AS files, COALESCE(SUM(staged_bytes), 0) AS bytes')
                ->first();

            return [
                'files' => (int) ($rows->files ?? 0),
                'bytes' => (int) ($rows->bytes ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('TranscriptionSubmitService::stagedInventory fallo: ' . $e->getMessage());

            return ['files' => 0, 'bytes' => 0];
        }
    }

    /**
     * Cataloga el node_id consultando /api/info una sola vez y cacheándolo
     * en memoria para evitar llamadas repetidas durante un lote de envíos.
     */
    private function resolveNodeId(): ?string
    {
        if ($this->cachedNodeId !== null) {
            return $this->cachedNodeId;
        }
        try {
            $info = $this->client->getInfo();
            $this->cachedNodeId = $info['node_id'] ?? null;
        } catch (\Throwable $e) {
            $this->cachedNodeId = null;
        }

        return $this->cachedNodeId;
    }

    private function markError(Transcription $t, string $message): void
    {
        $maxRetries = $this->settings->int('max_retries');
        $newRetries = (int) $t->retries + 1;

        $state = Transcription::STATE_ERROR;
        if ($newRetries >= $maxRetries) {
            $state = Transcription::STATE_DEAD;
            $message = "[Auto] Max retries ({$maxRetries}) alcanzado. {$message}";
        }

        // Higiene del tmpfs: una fila en error/dead no va a enviar su staged,
        // y dejarlo ocuparia presupuesto de RAM disk indefinidamente (el
        // sender solo procesa filas `pending`). Los caminos reintentables
        // usan markRequeueable(keepStaged: true) y no pasan por aqui.
        $this->discardStaged($t);

        $t->update([
            'state' => $state,
            'error_message' => $message,
            'finished_at' => now(),
            'retries' => $newRetries,
        ]);
    }

    /**
     * Marca la transcripcion como 'dead' inmediatamente, sin retry.
     * Para casos donde reintentar no aporta nada (archivo vacio, formato
     * invalido, etc.). Conserva retries anteriores para no romper el contador.
     */
    private function markDead(Transcription $t, string $message): void
    {
        $this->discardStaged($t);
        $t->update([
            'state' => Transcription::STATE_DEAD,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * Devuelve la transcripcion a 'pending' con requeue_after_at futuro, para
     * que el worker PG la ignore durante N minutos y la retome sola cuando
     * pase el plazo.
     *
     * Esto es independiente de la lógica de retries: un job rebotado por
     * tmpfs lleno o por cola remota saturada debe reintentar cada pocos
     * minutos, NO ir a dead tras max_retries.
     *
     * DEADLOCK CORREGIDO (2026-09-15): antes NO se limpiaba `dispatched_at`.
     * El worker PG reclama con `dispatched_at IS NULL`, asi que toda fila
     * rebotada por cola remota llena quedaba en pending + dispatched_at
     * poblado y NINGUN worker volvia a tomarla: requeue_after_at era codigo
     * muerto. Se midieron 1.746 filas del dia atascadas asi, con la cola
     * remota vacia y el pipeline en 0 envios/min.
     *
     * La invariante ahora es: `dispatched_at` = reclamada y en vuelo (lo
     * limpia el requeue), `requeue_after_at` = aplazamiento (lo respeta el
     * worker). Son dos señales distintas y no se pisan.
     *
     * @param  bool  $keepStaged  true cuando el rebote es de red: el audio ya
     *                            convertido se conserva para no quemar CPU.
     * @param  int|null  $requeueSeconds  aplazamiento especifico en segundos. Se
     *                            usa para el freno por cola remota, que debe
     *                            revalidar en ~30 s en vez de los minutos
     *                            genericos de `requeue_after_minutes`.
     */
    private function markRequeueable(Transcription $t, string $message, bool $keepStaged = false, ?int $requeueSeconds = null): void
    {
        $requeueMinutes = max(1, $this->settings->int('requeue_after_minutes'));

        if (!$keepStaged) {
            $this->discardStaged($t);
        }

        $requeueAt = $requeueSeconds !== null
            ? now()->addSeconds(max(5, $requeueSeconds))
            : now()->addMinutes($requeueMinutes);

        $t->update([
            'state' => Transcription::STATE_PENDING,
            'job_id' => null,
            'error_message' => $message,
            'finished_at' => null,
            'requeue_after_at' => $requeueAt,
            // Liberar la reclamacion para que el worker PG pueda re-tomarla
            // cuando venza el aplazamiento. Sin esto la fila queda huerfana.
            'dispatched_at' => null,
        ]);
    }
}
