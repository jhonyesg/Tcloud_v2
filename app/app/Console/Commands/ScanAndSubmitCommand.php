<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\DiskScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanAndSubmitCommand extends Command
{
    protected $signature = 'transcription:scan-and-submit
                            {--days=0 : Dias hacia atras ademas de hoy (0 = solo hoy)}
                            {--all : Escanear recursivamente todas las carpetas}
                            {--batch=0 : Maximo archivos por storage por ciclo (0 = usar config scan_batch)}
                            {--run-id= : Identificador para reportar progreso en cache (opcional)}
                            {--no-dispatch : Solo escanea y crea pending, NO encola a Redis}';

    protected $description = 'Escanea el disco de storages habilitados, crea transcripciones pendientes y las encola en Redis para que los workers supervisord las procesen en paralelo.';

    public function handle(DiskScannerService $scanner): int
    {
        $days = (int) $this->option('days');
        $all = (bool) $this->option('all');
        $batch = (int) $this->option('batch');
        $batchOverride = $batch > 0 ? $batch : null;
        $runId = $this->option('run-id');
        $cacheKey = $runId ? 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId) : null;

        if ($cacheKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, array_merge(
                \Illuminate\Support\Facades\Cache::get($cacheKey, []),
                ['status' => 'running', 'updated_at' => now()->toIso8601String()]
            ), now()->addHours(2));
        }

        $storages = StorageProvider::transcriptionEnabled()->get();
        if ($storages->isEmpty()) {
            $this->info('No hay storages con transcripción habilitada.');
            return Command::SUCCESS;
        }

        $totalPendingCreated = 0;
        $totalFilesCreated = 0;

        // Fase 1: escanear disco y crear pendientes.
        foreach ($storages as $storage) {
            $stats = $scanner->scanStorage($storage, $days, $all, $batchOverride);
            $totalFilesCreated += $stats['files_created'];
            $totalPendingCreated += $stats['transcriptions_created'];
            $this->info("Storage {$storage->name}: scanned={$stats['scanned']} candidates={$stats['candidates']} files_created={$stats['files_created']} tx_created={$stats['transcriptions_created']}");
        }

        // Fase 2: encolar pendientes sin job_id en Redis. El dispatch es NO
        // bloqueante: los workers supervisord (queue:work) consumen la cola y
        // ejecutan el pipeline ffmpeg+POST en paralelo (hasta numprocs simultáneos).
        $noDispatch = (bool) $this->option('no-dispatch');

        if ($noDispatch) {
            $this->info("Scan-and-submit (modo --no-dispatch) completado. Pendientes creados en BD: {$totalPendingCreated}. NO se encolo a Redis (encolado manual por scripts/transcription_enqueue_batch.php).");
            return Command::SUCCESS;
        }

        $submitBatch = ($batchOverride ?? (int) config('transcriptor.scan_batch', 100)) * max(1, $storages->count());
        $pending = Transcription::where('state', Transcription::STATE_PENDING)
            ->whereNull('job_id')
            ->limit($submitBatch)
            ->orderBy('created_at')
            ->get();

        $dispatched = 0;
        $errors = 0;
        foreach ($pending as $tx) {
            try {
                ConvertAndTranscribeJob::dispatch(
                    $tx->file_id,
                    (bool) $tx->generate_alerts
                );
                $dispatched++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Transcription {$tx->id}: {$e->getMessage()}");
                Log::error("ScanAndSubmitCommand: error encolando tx {$tx->id}: {$e->getMessage()}");
            }
        }

        $this->info("Scan-and-submit completado. Pendientes creados: {$totalPendingCreated}. Encolados: {$dispatched}. Errores: {$errors}. Los workers supervisord procesarán la cola en paralelo.");

        if ($cacheKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => 'queued',
                'batch' => 0,
                'processed' => 0,
                'errors' => 0,
                'total_to_process' => $dispatched,
                'total_candidates' => $totalPendingCreated,
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ], now()->addHours(2));
        }

        return Command::SUCCESS;
    }
}