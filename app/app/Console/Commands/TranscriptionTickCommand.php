<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
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
        $consoleKernel = $this->getLaravel()->make(ConsoleKernel::class);
        $exitCode = $consoleKernel->call('transcription:scan-and-submit', [
            '--days' => 0,
            '--batch' => $settings->int('scan_batch'),
            '--no-dispatch' => true,
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
            $this->warn('[tick] SCAN: ok; DISPATCH: pausado (dispatch_paused activo).');
            Log::info('TranscriptionTick: skip dispatch, dispatch_paused activo');
            return Command::SUCCESS;
        }

        // -------- Phase 2: Regulator dispatch --------
        $target = $settings->int('target_redis_queue');
        $runway = $settings->int('runway');

        $current = (int) Redis::llen('queues:transcription');

        // El deficit se evalua ANTES del clamp. Aplicar max($min, ...) primero
        // hacia inalcanzable el freno: con min_batch=10 el resultado nunca era
        // <= 0, asi que con la cola en 300 y target 140 la formula daba -155,
        // se elevaba a 10, y el tick seguia inyectando 10 jobs cada 2 minutos
        // sobre una cola ya saturada. min_batch es un piso que solo aplica
        // cuando existe margen real.
        $deficit = $target - $current + $runway;

        if ($deficit <= 0) {
            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: skip (queue ya en/sobre target, current=%d, target=%d, deficit=%d)",
                now()->format('Y-m-d H:i:s'),
                $current,
                $target,
                $deficit,
            );
            $this->line($msg);
            if (!$this->dryRun) {
                Log::info('TranscriptionTick: skip dispatch, queue at target', [
                    'current' => $current,
                    'target' => $target,
                    'deficit' => $deficit,
                ]);
            }
            return Command::SUCCESS;
        }

        $batch = $settings->computeDispatchBatch($current);

        // Query: pending del dia actual, sin job_id, FIFO.
        $query = Transcription::query()
            ->where('state', Transcription::STATE_PENDING)
            ->whereNull('job_id')
            ->where('created_at', '>=', $todayStart)
            ->orderBy('created_at', 'asc');

        $pendientes = $query->limit($batch)->pluck('file_id', 'id');

        if ($pendientes->isEmpty()) {
            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: 0 (no hay pending del dia actual; current=%d, target=%d, batch_computed=%d)",
                now()->format('Y-m-d H:i:s'),
                $current,
                $target,
                $batch,
            );
            $this->line($msg);
            if (!$this->dryRun) {
                Log::info('TranscriptionTick: no pending today', [
                    'current_redis' => $current,
                    'batch_computed' => $batch,
                ]);
            }
            return Command::SUCCESS;
        }

        if ($this->dryRun) {
            $msg = sprintf(
                "[tick DRY-RUN %s] SCAN: ok; DISPATCH: encolaria %d jobs de %d pendientes (current=%d, target=%d, batch_computed=%d)",
                now()->format('Y-m-d H:i:s'),
                min(count($pendientes), $batch),
                $pendientes->count(),
                $current,
                $target,
                $batch,
            );
            $this->line($msg);
            Log::info('TranscriptionTick: dry-run', [
                'would_dispatch' => min(count($pendientes), $batch),
                'available_today' => $pendientes->count(),
                'current_redis' => $current,
                'batch_computed' => $batch,
            ]);
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
            $target,
            $batch,
            $staggerMs,
        );
        $this->line($msg);
        Log::info('TranscriptionTick: dispatch', [
            'stagger_ms' => $staggerMs,
            'dispatched' => $dispatched,
            'errores' => $errores,
            'current_redis_before' => $current,
            'target' => $target,
            'batch_computed' => $batch,
        ]);

        return Command::SUCCESS;
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
