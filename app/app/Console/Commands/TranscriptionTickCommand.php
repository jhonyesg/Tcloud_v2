<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorSettings;
use App\Services\Ia\UpstreamCircuitBreaker;
use App\Support\TimeFormat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Planner puro del modulo de transcripcion.
 *
 * Frecuencia: cada 2 minutos (declarado en routes/console.php con ->everyTwoMinutes()).
 *
 * Fases:
 *  1. Discovery: invoca transcription:scan-and-submit --no-dispatch --days=0
 *     para descubrir archivos nuevos del dia actual y crear filas Transcription
 *     (state=pending, job_id=null) en BD.
 *
 *  2. Regulador: lee transcriptor.target_pg_queue (default 140) y calcula
 *     deficit = target - current + runway. Persiste la decision en cache para
 *     `/ia/api-transcriptor/regulator-cause`. NO encola — la cola es la propia
 *     tabla `transcriptions` y el worker PG (transcription:worker) la consume
 *     con FOR UPDATE SKIP LOCKED.
 *
 * Scope: TRANSCRIPTOR_SCOPE=current_day (default). Solo descubre archivos de hoy;
 *     dias anteriores requieren recuperacion manual via UI/bulk-dispatch.
 *
 * Por diseno NO despacha nada cuando:
 *  - el scope no es current_day (escapa a este tick automatico)
 *  - el regulador freno (cache de decision documenta el motivo)
 *  - no hay Transcription pendientes del dia actual
 */
class TranscriptionTickCommand extends Command
{
    protected $signature = 'transcription:tick
                            {--dry-run : Muestra conteos propuestos sin escribir en BD}';

    protected $description = 'Ciclo unificado: discovery (scan disco, dia actual) + evaluacion del regulador sobre la cola PG nativa.';

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

            $todayStart = BogotaTime::todayStart();

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
                TimeFormat::bogota(now(), 'Y-m-d H:i:s'),
                $decision['reason'],
                $decision['values']['pg_queue_depth'] ?? -1,
                $settings->int('target_pg_queue'),
            );
            $this->line($msg);
            Log::info('TranscriptionTick: skip dispatch, regulator decision', [
                'reason' => $decision['reason'],
                'signals' => $decision['values'],
            ]);
            return Command::SUCCESS;
        }

        $current = $decision['values']['pg_queue_depth'] ?? 0;
        $batch = $decision['batch_computed'];

        // Conteo de pending del dia actual para observabilidad y para saber
        // cuantos hay disponibles para que el worker PG los recoja.
        //
        // Se alinea con el filtro EXACTO del worker (state=pending, job_id nulo,
        // dispatched_at nulo, aplazamiento vencido) y con el eje `recorded_at`
        // que usa el worker — antes el tick medía `created_at` mientras el worker
        // filtraba por `recorded_at`, y los dos numeros no cuadraban nunca.
        $pendientes = (int) DB::table('transcriptions')
            ->where('state', Transcription::STATE_PENDING)
            ->whereNull('job_id')
            ->whereNull('dispatched_at')
            ->where('recorded_at', '>=', $todayStart)
            ->where(function ($q) {
                $q->whereNull('requeue_after_at')
                  ->orWhere('requeue_after_at', '<=', now());
            })
            ->count();

        if ($pendientes === 0) {
            // "No hay pending" se registraba igual estando el sistema sano y al
            // dia que estando el pipeline muerto por falta de storages
            // habilitados. Los dos casos son indistinguibles en el log, y por
            // eso el corte del 2026-08-18 paso 44 horas inadvertido: el tick
            // repitio esta misma linea cada 2 minutos sin que nadie sospechara.
            $storagesHabilitados = StorageProvider::transcriptionEnabled()->count();

            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: 0 (%s; current=%d, target=%d, batch_computed=%d, storages_habilitados=%d)",
                TimeFormat::bogota(now(), 'Y-m-d H:i:s'),
                $storagesHabilitados === 0
                    ? 'NINGUN storage con transcripcion habilitada'
                    : 'no hay pending del dia actual',
                $current,
                $settings->int('target_pg_queue'),
                $batch,
                $storagesHabilitados,
            );
            $this->line($msg);

            if (!$this->dryRun) {
                if ($storagesHabilitados === 0) {
                    Log::warning('TranscriptionTick: 0 storages con transcripcion habilitada; no hay nada que descubrir ni que enviar', [
                        'current_pg' => $current,
                        'pista' => 'ningun storage tiene transcription_enabled; encender los canales en /ia/api-transcriptor',
                    ]);
                } else {
                    Log::info('TranscriptionTick: no pending today', [
                        'current_pg' => $current,
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
                "[tick DRY-RUN %s] SCAN: ok; DISPATCH: worker tomaria hasta %d de %d pendientes (current=%d, target=%d, batch_computed=%d)",
                TimeFormat::bogota(now(), 'Y-m-d H:i:s'),
                min($pendientes, $batch),
                $pendientes,
                $current,
                $settings->int('target_pg_queue'),
                $batch,
            );
            $this->line($msg);
            Log::info('TranscriptionTick: dry-run', [
                'would_dispatch' => min($pendientes, $batch),
                'available_today' => $pendientes,
                'current_pg' => $current,
                'batch_computed' => $batch,
            ]);
            $decision['note'] = 'dry_run';
            $this->cacheDecision($decision);
            return Command::SUCCESS;
        }

        // Planner puro: el worker PG (transcription:worker) consume directamente
        // `transcriptions` con FOR UPDATE SKIP LOCKED. Solo dejamos evidencia
        // para el panel de diagnostico.
        $msg = sprintf(
            "[tick %s] SCAN: ok; DISPATCH: worker PG (pendientes_hoy=%d, current_pg=%d, target=%d, batch_computed=%d)",
            TimeFormat::bogota(now(), 'Y-m-d H:i:s'),
            $pendientes,
            $current,
            $settings->int('target_pg_queue'),
            $batch,
        );
        $this->line($msg);
        Log::info('TranscriptionTick: planner observability', [
            'pendientes_hoy' => $pendientes,
            'current_pg_before' => $current,
            'target' => $settings->int('target_pg_queue'),
            'batch_computed' => $batch,
            'regulator_mode' => $settings->str('regulator_mode'),
        ]);

        $decision['pendientes_hoy'] = $pendientes;
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
        $target = $settings->int('target_pg_queue');
        $current = $this->countPendingToday();
        $runway = $settings->int('runway');

        $values = [
            'pg_queue_depth' => $current,
            'pg_target' => $target,
        ];

        // upstream_circuit: Cualquier modo puede frenar si el break está abierto.
        $circuit = app(UpstreamCircuitBreaker::class);
        $circuitOpen = $circuit->isOpen();
        $values['upstream_circuit_open'] = $circuitOpen;
        $values['upstream_circuit'] = $circuit->summary();

        // shm_free_bytes: lo evalua cualquier modo (afecta a todos).
        $shmFree = @disk_free_space('/dev/shm');
        $shmFreeBytes = is_int($shmFree) ? $shmFree : null;
        if ($shmFreeBytes !== null) {
            $values['shm_free_bytes'] = $shmFreeBytes;
        }
        $minShm = $settings->int('min_shm_free_bytes');

        // remote_ram_pressure y remote_ramdisk_pressure: solo en remote_aware
        // y hybrid. Cacheado con Cache::remember para no castigar al nodo ASR.
        // gpu.util_pct NO se evalua como freno: 100% es normal cuando hay
        // trabajo pendiente; la senal real de salud es RAM y ramdisk.
        $remoteStats = null;
        $remoteInfo = null;
        if (in_array($mode, ['remote_aware', 'hybrid'], true)) {
            $cacheKey = 'transcriptor:remote_stats';
            $cacheTtl = max(1, $settings->int('regulator_remote_cache_seconds'));
            $remoteInfo = Cache::remember($cacheKey . ':info', $cacheTtl, function () use ($client) {
                return $client->getRemoteInfo();
            });
            $remoteStats = Cache::remember($cacheKey, $cacheTtl, function () use ($client) {
                return $client->getRemoteStats();
            });
            $values['remote_capacity'] = $remoteInfo['workers'] ?? null;
            $values['remote_ram_pct'] = $remoteInfo['ram_pct'] ?? null;
            $values['remote_ramdisk_pct'] = $remoteInfo['ramdisk_pct'] ?? null;
            $values['remote_queue_queued'] = $remoteInfo['queue_queued'] ?? null;
            $values['remote_gpu_vram_pct'] = $remoteInfo['gpu_vram_pct'] ?? null;
            $values['remote_gpu_util_pct_observability'] = $remoteInfo['gpu_util_pct'] ?? null;
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
        //   1. upstream_circuit_open        → upstream_circuit_open
        //   2. remote_ram_pressure          → remote_ram_pressure
        //   3. remote_ramdisk_pressure      → remote_ramdisk_pressure
        //   4. remote_queue_full            → remote_queue_full (NUEVO)
        //   5. pg_queue_depth <= 0           → queue_at_target
        //   6. shm_free_bytes               → shm_low
        //   7. inflight_active              → inflight_full
        //
        // NOTA: gpu.util_pct NO se evalua como freno. GPU al 100% es
        // senal de que esta haciendo el trabajo que le mandamos (es bueno),
        // no de que esta saturada. Los frenos reales son RAM (mata el
        // contenedor), ramdisk (llena /mnt/ramdisk) y cola remota (ya tiene
        // trabajo pendiente).
        $saturationPct = $settings->int('regulator_remote_saturation_pct');
        $ramdiskPressurePct = $settings->int('remote_ramdisk_pressure_pct');
        $ramPressurePct = $settings->int('remote_ram_pressure_pct');
        $skipped = false;
        $reason = 'none';

        $signalsEvaluated = ['pg_queue_depth'];
        if (in_array($mode, ['remote_aware', 'hybrid'], true)) {
            $signalsEvaluated[] = 'remote_ram_pressure';
            $signalsEvaluated[] = 'remote_ramdisk_pressure';
            $signalsEvaluated[] = 'remote_queue_full';
        }
        $signalsEvaluated[] = 'upstream_circuit';
        if ($mode === 'hybrid') {
            $signalsEvaluated[] = 'shm_free_bytes';
            if ($inflightMax > 0) {
                $signalsEvaluated[] = 'inflight_active';
            }
        } else {
            $signalsEvaluated[] = 'shm_free_bytes';
        }

        $checkCircuit = function () use ($circuitOpen) {
            return $circuitOpen;
        };

        $checkPg = function () use ($current, $target, $runway) {
            return $target - $current + $runway;
        };

        $checkRamdisk = function () use ($remoteInfo, $ramdiskPressurePct) {
            if ($remoteInfo === null) return false;
            return ($remoteInfo['ramdisk_pct'] ?? 0) >= $ramdiskPressurePct;
        };

        $checkRam = function () use ($remoteInfo, $ramPressurePct) {
            if ($remoteInfo === null) return false;
            return ($remoteInfo['ram_pct'] ?? 0) >= $ramPressurePct;
        };

        $checkRemoteQueueFull = function () use ($remoteInfo, $settings) {
            $brake = app(\App\Services\Ia\RemoteQueueBrake::class)->evaluate($remoteInfo, $settings);

            return (bool) ($brake['braked'] ?? false);
        };

        $checkShm = function () use ($shmFreeBytes, $minShm) {
            return $shmFreeBytes !== null && $shmFreeBytes < $minShm;
        };

        $checkInflight = function () use ($inflightActive, $inflightMax) {
            return $inflightActive !== null && $inflightActive >= $inflightMax;
        };

        switch ($mode) {
            case 'local_only':
                if ($checkCircuit()) {
                    $skipped = true; $reason = 'upstream_circuit_open';
                } elseif ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                } elseif ($checkPg() <= 0) {
                    $skipped = true; $reason = 'queue_at_target';
                }
                break;
            case 'remote_aware':
                if ($checkCircuit()) {
                    $skipped = true; $reason = 'upstream_circuit_open';
                } elseif ($checkRam()) {
                    $skipped = true; $reason = 'remote_ram_pressure';
                } elseif ($checkRamdisk()) {
                    $skipped = true; $reason = 'remote_ramdisk_pressure';
                } elseif ($checkRemoteQueueFull()) {
                    $skipped = true; $reason = 'remote_queue_full';
                } elseif ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                }
                break;
            case 'hybrid':
                if ($checkCircuit()) { $skipped = true; $reason = 'upstream_circuit_open'; }
                elseif ($checkRam()) { $skipped = true; $reason = 'remote_ram_pressure'; }
                elseif ($checkRamdisk()) { $skipped = true; $reason = 'remote_ramdisk_pressure'; }
                elseif ($checkRemoteQueueFull()) { $skipped = true; $reason = 'remote_queue_full'; }
                elseif ($checkShm()) { $skipped = true; $reason = 'shm_low'; }
                elseif ($checkInflight()) { $skipped = true; $reason = 'inflight_full'; }
                break;
            default:
                if ($checkCircuit()) {
                    $skipped = true; $reason = 'upstream_circuit_open';
                } elseif ($checkShm()) {
                    $skipped = true; $reason = 'shm_low';
                }
        }

        $deficit = $checkPg();

        if ($skipped) {
            return [
                'decision' => 'skipped',
                'reason' => $reason,
                'signals_evaluated' => $signalsEvaluated,
                'values' => $values,
                'batch_computed' => 0,
            ];
        }

        $baseBatch = $settings->computeDispatchBatch($current);
        $batch = $this->computeEffectiveBatch($settings, $baseBatch, $current, $remoteInfo);

        return [
            'decision' => 'dispatched',
            'reason' => 'none',
            'signals_evaluated' => $signalsEvaluated,
            'values' => $values,
            'deficit' => $deficit,
            'batch_computed' => $batch,
            'remote_info' => $remoteInfo,
        ];
    }

    /**
     * Batch efectivo con estrategia target-cola-remota.
     *
     * Idea: la API upstream expone queue.by_state_corrected["queued/0"] que
     * es la mejor senal real de carga. Mantenemos esa cola entre
     * [floor_remote_queue, target_remote_queue]:
     *
     *   cola <= floor        -> pulso completo (pulse_batch_size)
     *   cola >= target       -> 0 (frenar)
     *   cola en (floor, target) -> ramp lineal entre 1 y pulse_batch_size
     *
     * Despues aplicamos stuck_penalty para reducir si hay zombies, y
     * cortamos por el max_static del setting.
     */
    private function computeEffectiveBatch(TranscriptorSettings $settings, int $baseBatch, int $current, ?array $remoteInfo): int
    {
        $maxStatic = $settings->int('max_batch');
        $minBatch = $settings->int('min_batch');

        $targetQ = $settings->int('target_remote_queue');
        $floorQ  = $settings->int('floor_remote_queue');
        $pulse   = $settings->int('pulse_batch_size');

        $remoteQueue = $remoteInfo['queue_queued'] ?? null;

        // El freno con histéresis manda: si esta activo, el batch es 0 sin
        // importar la interpolacion. Sin esto, un queue apenas por debajo del
        // techo (ej. 179 tras drenar 1 job) daria un batch > 0 y volveria el
        // ping-pong que la histeresis elimina.
        $brake = app(\App\Services\Ia\RemoteQueueBrake::class)->evaluate($remoteInfo, $settings);
        if ($brake['braked'] ?? false) {
            return 0;
        }

        // Sin telemetria remota se falla ABIERTO con el pulso completo.
        //
        // Antes caia a `$baseBatch` (= computeDispatchBatch contra target_pg_queue),
        // y como la lista de pendientes del dia es ilimitada por diseño (puede
        // haber miles), esa aritmetica daba 0 y el tick dejaba de enviar por un
        // freno que no corresponde: el limite es de la cola REMOTA (180), no de
        // cuantos archivos del dia falten por transcribir.
        //
        // La profundidad local ya no es freno en `remote_aware`/`hybrid`
        // (evaluateRegulator no la evalua); usarla aqui como base del lote
        // reintroducia el freno por la puerta de atras.
        if ($remoteInfo === null || $remoteQueue === null) {
            $candidate = $pulse > 0 ? $pulse : $baseBatch;
        } elseif ($remoteQueue >= $targetQ) {
            $candidate = 0;
        } elseif ($remoteQueue <= $floorQ) {
            $candidate = $pulse;
        } else {
            $span = max(1, $targetQ - $floorQ);
            $headroom = $targetQ - $remoteQueue;
            $candidate = (int) max(1, round(($headroom / $span) * $pulse));
        }

        $stuckCount = Transcription::where('state', Transcription::STATE_QUEUED)
            ->where('started_at', '<', now()->subSeconds($settings->int('remote_wait_warn_seconds')))
            ->count();
        $stuckPenaltyPct = min(80, $stuckCount * $settings->int('stuck_penalty_pct'));
        $stuckMultiplier = (100 - $stuckPenaltyPct) / 100.0;
        $candidate = (int) floor($candidate * $stuckMultiplier);

        if ($candidate === 0) {
            return 0;
        }

        $effective = min($candidate, $maxStatic);
        $effective = max($minBatch, $effective);

        return max(0, $effective);
    }

    /**
     * Cuenta las filas `pending` con `recorded_at >= today Bogota`. Es la senal
     * `pg_queue_depth` que alimenta al regulador y a la UI de /ia/api-transcriptor.
     *
     * Implementado como COUNT(*) sobre la tabla `transcriptions` usando el indice
     * parcial `transcriptions_pending_today_dispatch_idx` (creado por la migration
     * 2026_09_15_130000). Tarda <5 ms incluso con backlog de 10k filas.
     */
    /**
     * Profundidad de la cola PG local: pendientes de hoy sin reclamar ni
     * aplazados. Es la señal `pg_queue_depth` del regulador.
     *
     * Se mide por `recorded_at` (igual que el worker) y no por `created_at`:
     * con `created_at`, una fila descubierta hoy pero con fecha de programa de
     * ayer se contaba como cola, mientras el worker nunca la tomaba. Los dos
     * numeros se contradecian en el panel.
     *
     * `job_id` y `dispatched_at` nulos + aplazamiento vencido replican el
     * filtro exacto de TranscriptionWorkerCommand::claimRow().
     */
    private function countPendingToday(): int
    {
        try {
            return (int) DB::table('transcriptions')
                ->where('state', Transcription::STATE_PENDING)
                ->whereNull('job_id')
                ->whereNull('dispatched_at')
                ->where('recorded_at', '>=', BogotaTime::todayStart())
                ->where(function ($q) {
                    $q->whereNull('requeue_after_at')
                      ->orWhere('requeue_after_at', '<=', now());
                })
                ->count();
        } catch (\Throwable $e) {
            Log::warning('TranscriptionTick: countPendingToday fallo: ' . $e->getMessage());

            return 0;
        }
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
        $todayStart = BogotaTime::todayStart();
            $total = 0;
            do {
                // `use ($reason)` es obligatorio: sin el, PHP evalua $reason
                // dentro del closure como variable local indefinida y el update
                // nunca se aplicaba (warning silencioso en cada tick).
                $affected = DB::table('transcriptions')
                    ->where('state', Transcription::STATE_PENDING)
                    ->whereNull('dispatched_at')
                    ->whereNull('job_id')
                    ->where('recorded_at', '>=', $todayStart)
                    ->where(function ($q) use ($reason) {
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
                ['fired_at' => TimeFormat::utc(now(), 'Y-m-d\TH:i:s'), 'regulator_mode' => app(TranscriptorSettings::class)->str('regulator_mode'), 'now_local' => TimeFormat::bogota(now(), 'Y-m-d H:i:s')],
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

            if ($last === null) {
                Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHours(6));
                return true;
            }

            $parsed = CarbonImmutable::parse($last);
            if (now()->diffInSeconds($parsed, true) < ($intervalMinutes * 60) - 5) {
                return false;
            }

            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHours(6));
        } catch (\Throwable $e) {
            Log::warning('TranscriptionTick: cache no disponible para el autolimitado: ' . $e->getMessage());
        }

        return true;
    }
}
