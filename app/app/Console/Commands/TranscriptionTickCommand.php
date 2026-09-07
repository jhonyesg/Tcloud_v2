<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Tick automatico del modulo de transcripcion.
 *
 * Frecuencia: cada 2 minutos (declarado en routes/console.php con ->everyTwoMinutes()).
 *
 * Fases:
 *  1. Discovery: invoca transcription:scan-and-submit --no-dispatch --days=0
 *     para descubrir archivos nuevos del dia actual y crear filas Transcription
 *     (state=pending, job_id=null) en BD. NO encola a Redis.
 *
 *  2. Regulator dispatch: lee transcriptor.target_redis_queue (default 140) y
 *     calcula deficit = target - current + runway. Si deficit <= 0, omite
 *     (queue ya en/sobre target). Si deficit > 0, batch = clamp(deficit,
 *     min_batch, max_batch) y dispatcha ConvertAndTranscribeJob para los
 *     primeros `batch` registros pendientes del dia actual ordenados por
 *     created_at ASC (FIFO).
 *
 *     El orden importa: el freno se evalua sobre el deficit crudo, NO sobre el
 *     valor ya clampeado. Aplicar min_batch antes del freno lo volvia codigo
 *     muerto y el tick seguia encolando sobre una cola saturada.
 *
 * Scope: TRANSCRIPTOR_SCOPE=current_day (default). Solo dispatcha archivos de hoy;
 *     dias anteriores requieren recuperacion manual via UI/bulk-dispatch.
 *
 * Por diseno NO dispatcha nada cuando:
 *  - el scope no es current_day (escapa a este tick automatico)
 *  - la cola Redis ya esta en/sobre target (regulador frena)
 *  - no hay Transcription pendientes del dia actual
 */
class TranscriptionTickCommand extends Command
{
    protected $signature = 'transcription:tick
                            {--dry-run : Muestra conteos propuestos sin escribir en BD/Redis}';

    protected $description = 'Ciclo unificado: discovery (scan disco, dia actual) + dispatch regulado por target_redis_queue.';

    /** Marca de la ultima ejecucion real, para el autolimitado por intervalo. */
    private const LAST_RUN_CACHE_KEY = 'transcriptor:tick:last_run';

    private bool $dryRun = false;

    public function handle(TranscriptorSettings $settings): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        // El scheduler corre cada minuto; el intervalo real lo decide este
        // autolimitado, asi que es ajustable en caliente sin tocar
        // routes/console.php ni recargar el cron.
        if (!$this->dryRun && !$this->intervalElapsed($settings)) {
            return Command::SUCCESS;
        }

        $scope = $settings->str('scope');
        if ($scope !== 'current_day') {
            $this->warn("Scope configurado a '{$scope}'. Tick automatico solo opera con scope=current_day. Use UI/bulk-dispatch para recuperacion manual.");
            Log::info("TranscriptionTick: skipped, scope={$scope}");
            return Command::SUCCESS;
        }

        $todayStart = CarbonImmutable::today();

        // -------- Phase 1: Discovery --------
        // Toda tx del tick entra al matching global de menciones; el filtrado
        // per-user ocurre en avisos:deliver-alerts. --alerts se pasa explicito
        // para que el diff documente la intencion aunque ScanAndSubmitCommand
        // ya lo defaulte a true (defensa en profundidad).
        $consoleKernel = $this->getLaravel()->make(ConsoleKernel::class);
        $exitCode = $consoleKernel->call('transcription:scan-and-submit', [
            '--days' => 0,
            '--batch' => $settings->int('scan_batch'),
            '--no-dispatch' => true,
            '--alerts' => true,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            $this->error("Phase 1 (scan) fallo con codigo {$exitCode}");
            Log::error("TranscriptionTick: Phase 1 scan fallo", ['exit' => $exitCode]);
            return $exitCode;
        }

        // -------- Freno de emergencia --------
        // Se comprueba DESPUES del descubrimiento a proposito: las filas pending
        // se siguen creando, solo se deja de encolar. Nada se pierde.
        if ($settings->bool('dispatch_paused')) {
            $decision = [
                'decision' => 'skipped',
                'reason' => 'dispatch_paused',
                'signals_evaluated' => ['dispatch_paused'],
                'values' => ['dispatch_paused' => true],
                'batch_computed' => 0,
            ];
            $this->cacheDecision($decision);
            $this->warn('[tick] SCAN: ok; DISPATCH: pausado (dispatch_paused activo).');
            Log::info('TranscriptionTick: skip dispatch, dispatch_paused activo');
            return Command::SUCCESS;
        }

        // -------- Phase 2: Regulator dispatch --------
        // El regulador ahora evalua segun `regulator_mode` (`local_only` |
        // `remote_aware` | `hybrid`) y decide si encolar o frenar. El detalle
        // de cada senal vive en evaluateRegulator().
        $regulator = app(TranscriptorApiClient::class);
        $decision = $this->evaluateRegulator($settings, $regulator);

        if ($decision['decision'] === 'skipped') {
            $this->persistRegulatorSkipReason($decision['reason']);
            $this->cacheDecision($decision);

            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: skip (%s, current=%d, target=%d)",
                now()->format('Y-m-d H:i:s'),
                $decision['reason'],
                $decision['values']['redis_queue_depth'] ?? -1,
                $settings->int('target_redis_queue'),
            );
            $this->line($msg);
            Log::info('TranscriptionTick: skip dispatch, regulator decision', [
                'reason' => $decision['reason'],
                'signals' => $decision['values'],
            ]);
            return Command::SUCCESS;
        }

        $batch = $decision['batch_computed'];
        $current = $decision['values']['redis_queue_depth'] ?? 0;

        // Query: pending del dia actual, sin job_id, FIFO.
        $query = Transcription::query()
            ->where('state', Transcription::STATE_PENDING)
            ->whereNull('job_id')
            ->where('created_at', '>=', $todayStart)
            // Excluir jobs rebotados por pre-flight (tmpfs sin espacio) cuyo
            // plazo de requeue aun no vencio. Ver TranscriptionSubmitService::
            // markRequeueable(). Sin este filtro, el tick reencolaria el job
            // inmediatamente y volveria a rebotar.
            ->where(function ($q) {
                $q->whereNull('requeue_after_at')
                  ->orWhere('requeue_after_at', '<=', now());
            })
            ->orderBy('created_at', 'asc');

        $pendientes = $query->limit($batch)->pluck('file_id', 'id');

        if ($pendientes->isEmpty()) {
            // "No hay pending" se registraba igual estando el sistema sano y al
            // dia que estando el pipeline muerto por falta de storages
            // habilitados. Los dos casos son indistinguibles en el log, y por
            // eso el corte del 2026-08-18 paso 44 horas inadvertido: el tick
            // repitio esta misma linea cada 2 minutos sin que nadie sospechara.
            $storagesHabilitados = StorageProvider::transcriptionEnabled()->count();

            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: 0 (%s; current=%d, target=%d, batch_computed=%d, storages_habilitados=%d)",
                now()->format('Y-m-d H:i:s'),
                $storagesHabilitados === 0
                    ? 'NINGUN storage con transcripcion habilitada'
                    : 'no hay pending del dia actual',
                $current,
                $settings->int('target_redis_queue'),
                $batch,
                $storagesHabilitados,
            );
            $this->line($msg);

            if (!$this->dryRun) {
                if ($storagesHabilitados === 0) {
                    Log::warning('TranscriptionTick: 0 storages con transcripcion habilitada; no hay nada que descubrir ni que enviar', [
                        'current_redis' => $current,
                        'pista' => 'ningun storage tiene transcription_enabled; encender los canales en /ia/api-transcriptor',
                    ]);
                } else {
                    Log::info('TranscriptionTick: no pending today', [
                        'current_redis' => $current,
                        'batch_computed' => $batch,
                        'storages_habilitados' => $storagesHabilitados,
                    ]);
                }
            }

            $decision['note'] = 'no_pending';
            $this->cacheDecision($decision);
            return Command::SUCCESS;
        }

        if ($this->dryRun) {
            $msg = sprintf(
                "[tick DRY-RUN %s] SCAN: ok; DISPATCH: encolaria %d jobs de %d pendientes (current=%d, target=%d, batch_computed=%d)",
                now()->format('Y-m-d H:i:s'),
                min(count($pendientes), $batch),
                $pendientes->count(),
                $current,
                $settings->int('target_redis_queue'),
                $batch,
            );
            $this->line($msg);
            Log::info('TranscriptionTick: dry-run', [
                'would_dispatch' => min(count($pendientes), $batch),
                'available_today' => $pendientes->count(),
                'current_redis' => $current,
                'batch_computed' => $batch,
            ]);
            $decision['note'] = 'dry_run';
            $this->cacheDecision($decision);
            return Command::SUCCESS;
        }

        // Goteo: con stagger > 0 los jobs entran a Redis escalonados en vez de
        // todos en el mismo instante. Es la diferencia entre 145 arranques
        // simultaneos de ffmpeg y 145 repartidos a lo largo del intervalo.
        $staggerMs = $settings->int('dispatch_stagger_ms');

        $dispatched = 0;
        $errores = 0;
        $stopAt = $batch;
        foreach ($pendientes as $txId => $fileId) {
            if ($dispatched >= $stopAt) break;
            try {
                // Marcamos `dispatched_at` ANTES del dispatch con un UPDATE crudo
                // para minimizar round-trips y cubrir el caso del worker que
                // muere justo despues del LPUSH: la siguiente vez que otro
                // worker tome el job, esta marca ya refleja el encolado real.
                DB::table('transcriptions')
                    ->where('id', $txId)
                    ->whereNull('dispatched_at')
                    ->update(['dispatched_at' => now()]);

                ConvertAndTranscribeJob::dispatch($fileId, true, $staggerMs > 0 ? $dispatched * $staggerMs : 0);
                $dispatched++;
            } catch (\Throwable $e) {
                $errores++;
                Log::error("TranscriptionTick: error encolando tx={$txId} file={$fileId}: " . $e->getMessage());
            }
        }

        $msg = sprintf(
            "[tick %s] SCAN: ok; DISPATCH: encolados=%d errores=%d (current_redis=%d, target=%d, batch_computed=%d, stagger_ms=%d)",
            now()->format('Y-m-d H:i:s'),
            $dispatched,
            $errores,
            $current,
            $settings->int('target_redis_queue'),
            $batch,
            $staggerMs,
        );
        $this->line($msg);
        Log::info('TranscriptionTick: dispatch', [
            'stagger_ms' => $staggerMs,
            'dispatched' => $dispatched,
            'errores' => $errores,
            'current_redis_before' => $current,
            'target' => $settings->int('target_redis_queue'),
            'batch_computed' => $batch,
            'regulator_mode' => $settings->str('regulator_mode'),
        ]);

        $decision['dispatched'] = $dispatched;
        $decision['errores'] = $errores;
        $this->cacheDecision($decision);

        return Command::SUCCESS;
    }

    /**
     * Evalua el conjunto de senales configurado por `regulator_mode` y
     * devuelve la decision del regulador para este ciclo.
     *
     * Salida:
     *   decision:        'dispatched' | 'skipped'
     *   reason:          una de {queue_at_target, shm_low, remote_gpu_saturated,
     *                        inflight_full, none}
     *   signals_evaluated: lista de nombres de senales consultadas (en orden)
     *   values:          mapa con el valor de cada senal evaluada
     *   batch_computed:  entero; lote que el regulador habria calculado
     */
    private function evaluateRegulator(TranscriptorSettings $settings, TranscriptorApiClient $client): array
    {
        $mode = $settings->str('regulator_mode');
        $target = $settings->int('target_redis_queue');
        $current = (int) Redis::llen('queues:transcription');
        $runway = $settings->int('runway');

        $values = [
            'redis_queue_depth' => $current,
            'redis_target' => $target,
        ];

        // shm_free_bytes: lo evalua cualquier modo (afecta a todos).
        $shmFree = @disk_free_space('/dev/shm');
        $shmFreeBytes = is_int($shmFree) ? $shmFree : null;
        if ($shmFreeBytes !== null) {
            $values['shm_free_bytes'] = $shmFreeBytes;
        }
        $minShm = $settings->int('min_shm_free_bytes');

        // remote_gpu_usage: solo en remote_aware y hybrid. Cacheado con
        // Cache::remember para no castigar al nodo ASR.
        $remoteStats = null;
        if (in_array($mode, ['remote_aware', 'hybrid'], true)) {
            $cacheKey = 'transcriptor:remote_stats';
            $cacheTtl = max(1, $settings->int('regulator_remote_cache_seconds'));
            $remoteStats = Cache::remember($cacheKey, $cacheTtl, function () use ($client, $settings) {
                $stats = $client->getRemoteStats();
                if ($stats === null) {
                    Log::info('TranscriptionTick: remote_stats no disponible, fail-open');
                }
                return $stats;
            });
            $values['remote_gpu_usage'] = $remoteStats['usage_pct'] ?? null;
        }

        // inflight_active: solo en hybrid y solo si inflight_max > 0.
        $inflightActive = null;
        $inflightMax = $settings->int('inflight_max');
        if ($mode === 'hybrid' && $inflightMax > 0) {
            $inflightActive = (int) (Cache::get('transcriptor:inflight:active', 0));
            $values['inflight_active'] = $inflightActive;
            $values['inflight_max'] = $inflightMax;
        }

        // Reglas de freno (orden de prioridad):
        //   1. redis_queue_depth >= target  → queue_at_target
        //   2. remote_gpu_usage >= umbral   → remote_gpu_saturated
        //   3. shm_free_bytes < min         → shm_low
        //   4. inflight_active >= inflight_max → inflight_full
        // local_only: solo evalua 1 y 3 (con la formula clasica).
        // remote_aware: evalua 2 antes que 1.
        // hybrid: evalua las cuatro en orden.
        $saturationPct = $settings->int('regulator_remote_saturation_pct');
        $skipped = false;
        $reason = 'none';

        $signalsEvaluated = ['redis_queue_depth'];
        if (in_array($mode, ['remote_aware', 'hybrid'], true)) {
            $signalsEvaluated[] = 'remote_gpu_usage';
        }
        if ($mode === 'hybrid') {
            $signalsEvaluated[] = 'shm_free_bytes';
            if ($inflightMax > 0) {
                $signalsEvaluated[] = 'inflight_active';
            }
        } else {
            $signalsEvaluated[] = 'shm_free_bytes';
        }

        $checkRedis = function () use ($current, $target, $runway, $settings) {
            // Formula clasica: deficit = target - current + runway.
            return $target - $current + $runway;
        };

        $checkRemote = function () use ($remoteStats, $saturationPct) {
            if ($remoteStats === null) {
                return false;
            }
            return ($remoteStats['usage_pct'] ?? 0) >= $saturationPct;
        };

        $checkShm = function () use ($shmFreeBytes, $minShm) {
            return $shmFreeBytes !== null && $shmFreeBytes < $minShm;
        };

        $checkInflight = function () use ($inflightActive, $inflightMax) {
            return $inflightActive !== null && $inflightActive >= $inflightMax;
        };

        switch ($mode) {
            case 'local_only':
                if ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                } elseif ($checkRedis() <= 0) {
                    $skipped = true; $reason = 'queue_at_target';
                }
                break;
            case 'remote_aware':
                if ($checkRemote()) {
                    $skipped = true; $reason = 'remote_gpu_saturated';
                } elseif ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                } elseif ($checkRedis() <= 0) {
                    $skipped = true; $reason = 'queue_at_target';
                }
                break;
            case 'hybrid':
                if ($checkRedis() <= 0) { $skipped = true; $reason = 'queue_at_target'; }
                elseif ($checkRemote()) { $skipped = true; $reason = 'remote_gpu_saturated'; }
                elseif ($checkShm()) { $skipped = true; $reason = 'shm_low'; }
                elseif ($checkInflight()) { $skipped = true; $reason = 'inflight_full'; }
                break;
            default:
                // Modo desconocido: comportamiento conservador = local_only.
                if ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                } elseif ($checkRedis() <= 0) {
                    $skipped = true; $reason = 'queue_at_target';
                }
        }

        $deficit = $checkRedis();

        if ($skipped) {
            return [
                'decision' => 'skipped',
                'reason' => $reason,
                'signals_evaluated' => $signalsEvaluated,
                'values' => $values,
                'batch_computed' => 0,
            ];
        }

        $batch = $settings->computeDispatchBatch($current);

        return [
            'decision' => 'dispatched',
            'reason' => 'none',
            'signals_evaluated' => $signalsEvaluated,
            'values' => $values,
            'deficit' => $deficit,
            'batch_computed' => $batch,
        ];
    }

    /**
     * Persiste `regulator_skip_reason` en todas las Transcriptions pendientes
     * del dia actual sin `dispatched_at`. Lo hace en batches de 1000 para no
     * generar escrituras masivas cuando hay backlogs grandes.
     */
    private function persistRegulatorSkipReason(string $reason): void
    {
        if ($this->dryRun) {
            return;
        }

        try {
            $todayStart = CarbonImmutable::today();
            $total = 0;
            do {
                $affected = DB::table('transcriptions')
                    ->where('state', Transcription::STATE_PENDING)
                    ->whereNull('dispatched_at')
                    ->whereNull('job_id')
                    ->where('created_at', '>=', $todayStart)
                    ->where(function ($q) {
                        $q->whereNull('regulator_skip_reason')
                          ->orWhere('regulator_skip_reason', '!=', $reason);
                    })
                    ->limit(1000)
                    ->update(['regulator_skip_reason' => $reason]);
                $total += $affected;
            } while ($affected > 0);

            if ($total > 0) {
                Log::info('TranscriptionTick: regulator_skip_reason actualizado', [
                    'reason' => $reason,
                    'rows' => $total,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('TranscriptionTick: persistRegulatorSkipReason fallo', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cachea la decision del regulador durante 1h para que el endpoint
     * `/ia/api-transcriptor/regulator-cause` la sirva sin recomputar.
     */
    private function cacheDecision(array $decision): void
    {
        if ($this->dryRun) {
            return;
        }
        try {
            $payload = array_merge(
                ['fired_at' => now()->toIso8601String(), 'regulator_mode' => app(TranscriptorSettings::class)->str('regulator_mode')],
                $decision,
            );
            Cache::put('transcriptor:tick:last_decision', $payload, now()->addHour());
        } catch (\Throwable $e) {
            Log::warning('TranscriptionTick: no se pudo cachear la decision del regulador', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Autolimitado por intervalo.
     *
     * El schedule invoca este comando cada minuto; aqui se decide si toca
     * ejecutar segun tick_interval_minutes. Asi la frecuencia es ajustable en
     * caliente desde la UI, sin editar routes/console.php ni recargar el cron.
     *
     * Si la cache no esta disponible se ejecuta igualmente: preferimos correr de
     * mas a quedarnos sin descubrimiento. El regulador y dispatch_paused siguen
     * acotando el volumen.
     */
    private function intervalElapsed(TranscriptorSettings $settings): bool
    {
        $intervalMinutes = max(1, $settings->int('tick_interval_minutes'));

        try {
            $last = Cache::get(self::LAST_RUN_CACHE_KEY);

            if ($last !== null && now()->diffInSeconds(CarbonImmutable::parse($last), true) < ($intervalMinutes * 60) - 5) {
                return false;
            }

            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHours(6));
        } catch (\Throwable $e) {
            Log::warning('TranscriptionTick: cache no disponible para el autolimitado: ' . $e->getMessage());
        }

        return true;
    }
}
