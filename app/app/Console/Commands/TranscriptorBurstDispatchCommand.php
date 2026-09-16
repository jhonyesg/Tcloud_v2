<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\BogotaTime;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daemon que drena el backlog de transcriptions contra la API remota
 * manteniendo la cola upstream acotada (default < 180).
 *
 * Flujo (loop infinito):
 *  1. Lee /api/metrics/overview de la API remota.
 *  2. Calcula inflight = queued + processing.
 *  3. Si inflight >= max_upstream_queue → duerme poll_interval y repite.
 *  4. Calcula headroom = max_upstream_queue - inflight.
 *  5. Toma hasta min(headroom, batch_size) filas `pending` de la BD.
 *  6. Lanza `transcriptor:dispatch-one` en paralelo (proc_open) sobre
 *     `parallel_ffmpeg` slots. Cada slot procesa 1 audio (ffmpeg + POST).
 *  7. Espera que todos los slots terminen (pool de procesos).
 *  8. Repite desde paso 1.
 *
 * Por diseno:
 *  - ffmpeg local SI corre, pero limitado a `parallel_ffmpeg` simultaneos
 *    (default 4), para no saturar CPU/ramdisk del server.
 *  - La cola de la API remota nunca pasa de max_upstream_queue (default 180)
 *    porque el headroom real-time limita el batch.
 *  - Cuando no hay candidatos, el daemon duerme poll_interval y reintenta
 *    (no spinloop).
 *  - Daemon continuo: correr en una unit supervisord o como `nohup` con PID
 *    para permitir SIGTERM limpio (Laravel lo maneja en commands largos).
 *
 * Persistencia de estado en cache key `transcriptor:burst:status`:
 *  - last_run_at, last_upstream_inflight, last_headroom, last_batch_size,
 *    last_dispatched, last_run_id
 * Sirve para que la UI /ia/api-transcriptor vea en vivo el estado del daemon.
 */
class TranscriptorBurstDispatchCommand extends Command
{
    protected $signature = 'transcriptor:burst-dispatch
                            {--max-runs=0 : Salir despues de N rafagas (0 = infinito, util para test)}
                            {--once : Alias de --max-runs=1 (procesa UNA rafaga y sale)}';

    protected $description = 'Daemon: drena backlog contra API remota manteniendo cola upstream acotada.';

    private bool $shouldStop = false;
    private int $runCounter = 0;
    private string $runId;

    public function __construct(
        private TranscriptorSettings $settings,
    ) {
        parent::__construct();
    }

    public function handle(
        TranscriptorApiClient $client,
    ): int {
        $this->installSignalHandlers();

        $maxRuns = (int) $this->option('max-runs');
        if ($this->option('once')) {
            $maxRuns = 1;
        }

        $this->runId = 'brst_' . substr(bin2hex(random_bytes(4)), 0, 8);
        Log::info('transcriptor.burst.started', [
            'run_id' => $this->runId,
            'pid' => getmypid(),
            'max_runs' => $maxRuns,
        ]);

        $this->line("Burst-dispatch daemon started (run_id={$this->runId}, max_runs={$maxRuns})");

        while (!$this->shouldStop) {
            $this->dispatchSignals();

            try {
                $dispatched = $this->tick($this->settings, $client);
            } catch (\Throwable $e) {
                Log::error('transcriptor.burst.tick_error: ' . $e->getMessage(), [
                    'run_id' => $this->runId,
                    'exception' => $e::class,
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->dispatchSignals();
                $this->sleepWithSignal($this->settings->int('burst_poll_interval_seconds'));
                continue;
            }

            $this->runCounter++;
            $this->persistStatus($this->settings, $dispatched);

            if ($maxRuns > 0 && $this->runCounter >= $maxRuns) {
                Log::info('transcriptor.burst.max_runs_reached', [
                    'run_id' => $this->runId,
                    'max_runs' => $maxRuns,
                ]);
                break;
            }

            // Si dispatchio algo, vuelve al loop inmediatamente (sigue habiendo
            // candidatos). Si no, duerme poll_interval.
            if ($dispatched['dispatched'] === 0) {
                $this->dispatchSignals();
                $this->sleepWithSignal($this->settings->int('burst_poll_interval_seconds'));
            }
        }

        Log::info('transcriptor.burst.stopped', [
            'run_id' => $this->runId,
            'total_runs' => $this->runCounter,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Ejecuta una iteracion completa: consulta upstream, calcula headroom,
     * toma lote, procesa con parallel_ffmpeg slots.
     *
     * @return array{dispatched:int, batch_size:int, headroom:int, upstream_inflight:int}
     */
    private function tick(TranscriptorSettings $settings, TranscriptorApiClient $client): array
    {
        $start = microtime(true);
        $maxQueue = $settings->int('burst_max_upstream_queue');
        $batchSetting = $settings->int('burst_batch_size');
        $parallel = max(1, $settings->int('burst_parallel_ffmpeg'));
        $apiBaseUrl = rtrim($client->getBaseUrl(), '/');

        // 1) Leer cola upstream
        $overview = $this->fetchUpstreamOverview($apiBaseUrl);
        if ($overview === null) {
            // Upstream no responde; dormir y reintentar
            $this->warn('Upstream /api/metrics/overview no responde; pausa breve.');
            $this->sleepWithSignal(5);
            return ['dispatched' => 0, 'batch_size' => 0, 'headroom' => 0, 'upstream_inflight' => -1];
        }

        $upstreamInflight = $this->extractInflight($overview);
        $headroom = max(0, $maxQueue - $upstreamInflight);

        if ($headroom < 10) {
            $this->line(sprintf(
                '[run=%d] inflight=%d >= max=%d → pausa',
                $this->runCounter + 1,
                $upstreamInflight,
                $maxQueue,
            ));
            return ['dispatched' => 0, 'batch_size' => 0, 'headroom' => $headroom, 'upstream_inflight' => $upstreamInflight];
        }

        // 2) Calcular tamano del lote (capado por headroom y por batch_size)
        $batchSize = min($headroom, $batchSetting);
        if ($batchSize < 1) {
            return ['dispatched' => 0, 'batch_size' => 0, 'headroom' => $headroom, 'upstream_inflight' => $upstreamInflight];
        }

        // 3) Tomar lote de pendientes (orden FIFO por recorded_at)
        $today = BogotaTime::todayStart();
        $candidates = DB::table('transcriptions')
            ->join('files', 'files.id', '=', 'transcriptions.file_id')
            ->where('transcriptions.state', Transcription::STATE_PENDING)
            ->whereNull('transcriptions.dispatched_at')
            ->where('transcriptions.recorded_at', '>=', $today)
            ->orderBy('transcriptions.recorded_at', 'desc')
            ->orderBy('transcriptions.discovered_at', 'desc')
            ->limit($batchSize)
            ->select(
                'transcriptions.id',
                'transcriptions.file_id',
                'transcriptions.recorded_at',
                'transcriptions.discovered_at',
                'transcriptions.state',
                'files.storage_provider_id',
                'files.path',
                'files.name',
            )
            ->get();

        if ($candidates->isEmpty()) {
            return ['dispatched' => 0, 'batch_size' => 0, 'headroom' => $headroom, 'upstream_inflight' => $upstreamInflight];
        }

        // 3.5) VALIDACIONES DEFENSIVAS: el scanner YA filtra, pero el operador
        //      confirmo que "siempre el archivo mas reciente se esta generando".
        //      Aplicamos 3 checks antes de despachar para evitar enviar audios
        //      aun siendo escritos por ffmpeg.
        $validated = $this->validateCandidatesAgainstDisk($candidates, $today);
        $skippedDefensive = $candidates->count() - count($validated);
        if ($skippedDefensive > 0) {
            $this->line(sprintf(
                '  defensiva: %d candidatos descartados por estar aun en grabacion',
                $skippedDefensive,
            ));
        }
        if (empty($validated)) {
            return [
                'dispatched' => 0,
                'batch_size' => $candidates->count(),
                'headroom' => $headroom,
                'upstream_inflight' => $upstreamInflight,
            ];
        }
        $candidates = collect($validated);

        // 4) Procesar lote con parallel_ffmpeg slots (pool de procesos)
        $this->line(sprintf(
            '[run=%d] lote=%d candidatos (headroom=%d, inflight=%d, parallel=%d)',
            $this->runCounter + 1,
            $candidates->count(),
            $headroom,
            $upstreamInflight,
            $parallel,
        ));

        $dispatched = $this->processBatchParallel($candidates->pluck('id')->map(fn ($v) => (int) $v)->all(), $parallel);

        $duration = (int) round((microtime(true) - $start) * 1000);
        Log::info('transcriptor.burst.rafaga_done', [
            'run_id' => $this->runId,
            'batch_size' => $candidates->count(),
            'dispatched' => $dispatched,
            'headroom_before' => $headroom,
            'upstream_inflight_before' => $upstreamInflight,
            'parallel' => $parallel,
            'duration_ms' => $duration,
        ]);

        $this->line(sprintf(
            '[run=%d] dispatched=%d (duration=%dms)',
            $this->runCounter + 1,
            $dispatched,
            $duration,
        ));

        return [
            'dispatched' => $dispatched,
            'batch_size' => $candidates->count(),
            'headroom' => $headroom,
            'upstream_inflight' => $upstreamInflight,
        ];
    }

    /**
     * Aplica UNA validacion defensiva a cada candidato antes de despachar:
     *
     *   UNICA: NO es el archivo mas reciente del storage (por recorded_at).
     *          Cobertura principal contra "siempre el mas reciente se esta generando"
     *          (operador 2026-09-15). Se compara contra MAX(recorded_at) por
     *          storage_provider_id en BD.
     *
     * Las validaciones mtime y fd-abierto se ELIMINARON porque descartaban
     * candidatos legítimos:
     *   - mtime > 10 min: descartaba audios recien completados por scrapers
     *     que terminaron de escribir hace <10min pero ya estaban completos.
     *   - fd abierto (lsof): era lento y la regla del mas reciente ya cubre
     *     el caso típico (los ffmpegs en vivo siempre escriben al archivo
     *     con recorded_at mas alto del storage).
     *
     * NO se valida tamano: mp3/m4a pesan diferente a mp4 y un umbral unico
     * descartaria audios legitimos (decision del operador 2026-09-15).
     *
     * @param  \Illuminate\Support\Collection  $candidates
     * @return array<int, object> Solo los candidatos que pasan la validacion
     */
    private function validateCandidatesAgainstDisk(\Illuminate\Support\Collection $candidates, CarbonImmutable $today): array
    {
        // Precomputar latest recorded_at por storage_provider_id (consulta 1 sola vez)
        $latestPerStorage = DB::table('transcriptions as t')
            ->join('files as f', 'f.id', '=', 't.file_id')
            ->where('t.recorded_at', '>=', $today)
            ->whereIn('t.state', [Transcription::STATE_PENDING, Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING, Transcription::STATE_DONE])
            ->select('f.storage_provider_id', DB::raw('MAX(t.recorded_at) as max_recorded'))
            ->groupBy('f.storage_provider_id')
            ->pluck('max_recorded', 'storage_provider_id');

        $validated = [];
        foreach ($candidates as $c) {
            $reasons = [];

            // UNICA validacion: NO es el archivo mas reciente del storage
            $latest = $latestPerStorage[$c->storage_provider_id] ?? null;
            if ($latest !== null && $c->recorded_at === $latest) {
                $reasons[] = 'is_latest_for_storage';
            }

            if (empty($reasons)) {
                $validated[] = $c;
            } else {
                Log::info('transcriptor.burst.defensive_skip', [
                    'run_id' => $this->runId,
                    'tx_id' => $c->id,
                    'reasons' => $reasons,
                ]);
            }
        }

        return $validated;
    }

    private function fetchUpstreamOverview(string $apiBaseUrl): ?array
    {
        try {
            $resp = Http::timeout(5)->get($apiBaseUrl . '/api/metrics/overview');
            if (!$resp->ok()) {
                return null;
            }
            return $resp->json();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extrae inflight = queued + processing del campo by_state_corrected.
     * Si no esta disponible, cae a queue.queued (legacy).
     */
    private function extractInflight(array $overview): int
    {
        $byState = $overview['queue']['by_state_corrected'] ?? null;
        if (is_array($byState)) {
            $queued = (int) ($byState['queued/0'] ?? 0);
            $processing = (int) ($byState['processing/0'] ?? 0);
            return $queued + $processing;
        }

        // Fallback legacy: queue.queued y queue.processing directos
        $queue = $overview['queue'] ?? [];
        return (int) ($queue['queued'] ?? 0) + (int) ($queue['processing'] ?? 0);
    }

    /**
     * Pool de procesos: hasta $parallel slots ejecutan
     * `transcriptor:dispatch-one --tx=N --parent-run-id=X` en paralelo via
     * proc_open. Cuando un slot termina, se le asigna el siguiente tx_id.
     *
     * @param  list<int>  $txIds
     * @return int Cantidad de audios despachados (state=queued)
     */
    private function processBatchParallel(array $txIds, int $parallel): int
    {
        $dispatched = 0;
        $errors = 0;
        $requeues = 0;
        $queue = $txIds; // cola FIFO de tx_ids por procesar
        // PHP 8.4 no permite usar resources como array key (deprecated),
        // asi que usamos un map<slot:int, info> donde info lleva el proc.
        /** @var array<int, array{proc:resource, tx:int, started:float, pipes:array}> $running */
        $running = [];
        $artisan = base_path('artisan');
        $nextSlot = 0;

        while (!empty($queue) || !empty($running)) {
            // Llenar slots vacios hasta $parallel
            while (count($running) < $parallel && !empty($queue)) {
                $txId = array_shift($queue);
                $proc = proc_open(
                    ['/usr/bin/php84', $artisan, 'transcriptor:dispatch-one', '--tx=' . $txId, '--parent-run-id=' . $this->runId],
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    base_path()
                );

                if (!is_resource($proc)) {
                    Log::warning('transcriptor.burst.proc_open_failed', ['tx_id' => $txId]);
                    $errors++;
                    continue;
                }

                $slotId = $nextSlot++;
                $running[$slotId] = [
                    'proc' => $proc,
                    'tx' => $txId,
                    'started' => microtime(true),
                    'pipes' => $pipes,
                ];
                stream_set_blocking($running[$slotId]['pipes'][1], false);
            }

            if (empty($running)) {
                break;
            }

            // Esperar a que AL MENOS un proceso termine via stream_select
            $readPipes = [];
            $slotToPipe = [];
            foreach ($running as $slotId => $info) {
                $readPipes[] = $info['pipes'][1];
                $slotToPipe[(int) $info['pipes'][1]] = $slotId;
            }

            $write = null;
            $except = null;
            // timeout 5s para no bloquear indefinidamente
            stream_select($readPipes, $write, $except, 5, 0);

            // Iterar slots para detectar terminados
            foreach (array_keys($running) as $slotId) {
                $info = $running[$slotId];
                $status = proc_get_status($info['proc']);
                if (!$status['running']) {
                    // Proceso terminó: recolectar resultado
                    $stdout = stream_get_contents($info['pipes'][1]);
                    fclose($info['pipes'][1]);
                    fclose($info['pipes'][2]);
                    proc_close($info['proc']);

                    $duration = (int) round((microtime(true) - $info['started']) * 1000);
                    $parsed = $this->parseDispatchOneOutput($stdout);
                    if (($parsed['state'] ?? null) === 'queued') {
                        $dispatched++;
                        Log::info('transcriptor.burst.dispatched', [
                            'run_id' => $this->runId,
                            'tx_id' => $parsed['tx_id'] ?? $info['tx'],
                            'job_id' => $parsed['job_id'] ?? null,
                            'duration_ms' => $duration,
                        ]);
                    } elseif (($parsed['requeueable'] ?? false)) {
                        $requeues++;
                        Log::info('transcriptor.burst.requeue', [
                            'run_id' => $this->runId,
                            'tx_id' => $parsed['tx_id'] ?? $info['tx'],
                            'reason' => $parsed['reason'] ?? null,
                        ]);
                    } else {
                        $errors++;
                        Log::warning('transcriptor.burst.dispatch_failed', [
                            'run_id' => $this->runId,
                            'tx_id' => $parsed['tx_id'] ?? $info['tx'],
                            'error' => $parsed['error'] ?? 'unknown',
                            'stdout' => $stdout,
                            'duration_ms' => $duration,
                        ]);
                    }

                    unset($running[$slotId]);
                }
            }
        }

        $this->line(sprintf(
            '  rafaga resultado: dispatched=%d requeue=%d errors=%d',
            $dispatched,
            $requeues,
            $errors,
        ));

        return $dispatched;
    }

    private function parseDispatchOneOutput(string $stdout): array
    {
        // El comando dispatch-one imprime una linea JSON al final.
        // Buscar la ultima linea que sea JSON valido.
        $lines = explode("\n", trim($stdout));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function persistStatus(TranscriptorSettings $settings, array $dispatched): void
    {
        $status = [
            'run_id' => $this->runId,
            'pid' => getmypid(),
            'last_run_at' => now()->toIso8601String(),
            'last_dispatched' => $dispatched['dispatched'],
            'last_batch_size' => $dispatched['batch_size'],
            'last_headroom' => $dispatched['headroom'],
            'last_upstream_inflight' => $dispatched['upstream_inflight'],
            'max_upstream_queue' => $settings->int('burst_max_upstream_queue'),
            'batch_size' => $settings->int('burst_batch_size'),
            'parallel_ffmpeg' => $settings->int('burst_parallel_ffmpeg'),
            'poll_interval_seconds' => $settings->int('burst_poll_interval_seconds'),
            'total_runs' => $this->runCounter,
        ];

        Cache::put('transcriptor:burst:status', $status, now()->addMinutes(10));
    }

    private function sleepWithSignal(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldStop; $i++) {
            $this->dispatchSignals();
            sleep(1);
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
        });
        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
        });
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}