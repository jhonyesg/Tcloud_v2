<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\Transcription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
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
 *  2. Regulator dispatch: lee config transcriptor.target_redis_queue (default 140)
 *     y calcula batch = clamp(target - current + runway, min_batch, max_batch).
 *     Si batch <= 0, omite (queue ya en target). Si batch > 0, dispatcha
 *     ConvertAndTranscribeJob para los primeros `batch` registros pendientes del
 *     dia actual ordenados por created_at ASC (FIFO).
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

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $scope = (string) config('transcriptor.scope', 'current_day');
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
            '--batch' => (int) config('transcriptor.scan_batch', 100),
            '--no-dispatch' => true,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            $this->error("Phase 1 (scan) fallo con codigo {$exitCode}");
            Log::error("TranscriptionTick: Phase 1 scan fallo", ['exit' => $exitCode]);
            return $exitCode;
        }

        // -------- Phase 2: Regulator dispatch --------
        $target = (int) config('transcriptor.target_redis_queue', 140);
        $min = (int) config('transcriptor.min_batch', 10);
        $max = (int) config('transcriptor.max_batch', 200);
        $runway = (int) config('transcriptor.runway', 5);

        $current = (int) Redis::llen('queues:transcription');
        $batch = max($min, min($max, $target - $current + $runway));

        if ($batch <= 0) {
            $msg = sprintf(
                "[tick %s] SCAN: ok; DISPATCH: skip (queue ya en/sobre target, current=%d, target=%d)",
                now()->format('Y-m-d H:i:s'),
                $current,
                $target,
            );
            $this->line($msg);
            if (!$this->dryRun) {
                Log::info('TranscriptionTick: skip dispatch, queue at target', [
                    'current' => $current,
                    'target' => $target,
                ]);
            }
            return Command::SUCCESS;
        }

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

        $dispatched = 0;
        $errores = 0;
        $stopAt = $batch;
        foreach ($pendientes as $txId => $fileId) {
            if ($dispatched >= $stopAt) break;
            try {
                ConvertAndTranscribeJob::dispatch($fileId, true);
                $dispatched++;
            } catch (\Throwable $e) {
                $errores++;
                Log::error("TranscriptionTick: error encolando tx={$txId} file={$fileId}: " . $e->getMessage());
            }
        }

        $msg = sprintf(
            "[tick %s] SCAN: ok; DISPATCH: encolados=%d errores=%d (current_redis=%d, target=%d, batch_computed=%d)",
            now()->format('Y-m-d H:i:s'),
            $dispatched,
            $errores,
            $current,
            $target,
            $batch,
        );
        $this->line($msg);
        Log::info('TranscriptionTick: dispatch', [
            'dispatched' => $dispatched,
            'errores' => $errores,
            'current_redis_before' => $current,
            'target' => $target,
            'batch_computed' => $batch,
        ]);

        return Command::SUCCESS;
    }
}
