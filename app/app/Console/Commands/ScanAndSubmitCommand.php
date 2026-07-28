<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Services\Ia\DiskScannerService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ScanAndSubmitCommand extends Command
{
    protected $signature = 'transcription:scan-and-submit
                            {--days=-1 : Dias hacia atras ademas de hoy (-1 = usar scan_days_back)}
                            {--all : Escanear recursivamente todas las carpetas}
                            {--batch=0 : Maximo archivos por storage por ciclo (0 = usar config scan_batch)}
                            {--run-id= : Identificador para reportar progreso en cache (opcional)}
                            {--no-dispatch : Solo escanea y crea pending, NO encola a Redis}
                            {--alerts : Generar avisos inteligentes para las transcripciones creadas}
                            {--include-failed : Incluir transcripciones en estado error con archivo accesible (max retries configurable)}';

    protected $description = 'Escanea el disco de storages habilitados, crea transcripciones pendientes y las encola en Redis para que los workers supervisord las procesen en paralelo.';

    public function handle(DiskScannerService $scanner, TranscriptorSettings $settings): int
    {
        try {
            return $this->runHandle($scanner, $settings);
        } catch (\Throwable $e) {
            $runId = $this->option('run-id');
            $cacheKey = $runId ? 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId) : null;

            Log::error("ScanAndSubmitCommand: error fatal: {$e->getMessage()}", ['exception' => $e]);

            if ($cacheKey) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'batch' => 0,
                    'processed' => 0,
                    'errors' => 1,
                    'total_to_process' => 0,
                    'total_candidates' => 0,
                    'per_storage_errors' => [],
                    'failed_recovered' => 0,
                    'failed_promoted_to_dead' => 0,
                    'failed_skipped_max_retries' => 0,
                    'storages' => [],
                    'files' => [],
                    'started_at' => now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(2));
            }

            return Command::FAILURE;
        }
    }

    private function runHandle(DiskScannerService $scanner, TranscriptorSettings $settings): int
    {
        // -1 = no especificado; se usa scan_days_back, que hasta ahora estaba
        // definido en config y no lo leia nadie.
        $days = (int) $this->option('days');
        $days = $days < 0 ? $settings->int('scan_days_back') : $days;

        $all = (bool) $this->option('all');
        $batch = (int) $this->option('batch');
        $batchOverride = $batch > 0 ? $batch : null;
        $runId = $this->option('run-id');
        $cacheKey = $runId ? 'transcription_batch:' . preg_replace('/[^a-z0-9_\-]/i', '_', $runId) : null;
        $includeFailed = (bool) $this->option('include-failed');
        $generateAlerts = (bool) $this->option('alerts');
        $maxRetries = $settings->int('max_retries');

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
        $perStorageErrors = [];
        $failedStorages = 0;

        // Stats de fallidos (solo si --include-failed)
        $failedStats = [
            'candidates' => 0,
            'reset_to_pending' => 0,
            'promoted_to_dead' => 0,
            'skipped_max_retries' => 0,
        ];

        // Fase 1: escanear disco y crear pendientes.
        foreach ($storages as $storage) {
            try {
                $stats = $scanner->scanStorage($storage, $days, $all, $batchOverride, $generateAlerts);
                $totalFilesCreated += $stats['files_created'];
                $totalPendingCreated += $stats['transcriptions_created'];
                $this->info("Storage {$storage->name}: scanned={$stats['scanned']} candidates={$stats['candidates']} files_created={$stats['files_created']} tx_created={$stats['transcriptions_created']}");
            } catch (\Throwable $e) {
                $failedStorages++;
                $msg = "Storage {$storage->name} (id={$storage->id}): {$e->getMessage()}";
                $this->error($msg);
                Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
                $perStorageErrors[] = [
                    'storage_id' => $storage->id,
                    'storage_name' => $storage->name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        // Fase 1.5: reintentar transcripciones en estado error (solo si --include-failed).
        if ($includeFailed) {
            foreach ($storages as $storage) {
                try {
                    $stats = $scanner->collectFailedCandidates($storage, $maxRetries);
                    foreach ($stats as $k => $v) {
                        $failedStats[$k] = ($failedStats[$k] ?? 0) + $v;
                    }
                    if (($stats['candidates'] ?? 0) > 0) {
                        $this->info("Storage {$storage->name} (retry): candidates={$stats['candidates']} reset={$stats['reset_to_pending']} dead={$stats['promoted_to_dead']} skipped_max={$stats['skipped_max_retries']}");
                    }
                } catch (\Throwable $e) {
                    $msg = "Storage {$storage->name} (collect-failed): {$e->getMessage()}";
                    $this->error($msg);
                    Log::error("ScanAndSubmitCommand: {$msg}", ['exception' => $e]);
                    $perStorageErrors[] = [
                        'storage_id' => $storage->id,
                        'storage_name' => $storage->name,
                        'message' => 'collect-failed: ' . $e->getMessage(),
                    ];
                }
            }
            $this->info("Retry-failed resumen: candidates={$failedStats['candidates']} reset_to_pending={$failedStats['reset_to_pending']} promoted_to_dead={$failedStats['promoted_to_dead']} skipped_max_retries={$failedStats['skipped_max_retries']}");
        }

        // Fase 2: encolar pendientes sin job_id en Redis. El dispatch es NO
        // bloqueante: los workers supervisord (queue:work) consumen la cola y
        // ejecutan el pipeline ffmpeg+POST en paralelo (hasta numprocs simultáneos).
        $noDispatch = (bool) $this->option('no-dispatch');

        if ($noDispatch) {
            $this->info("Scan-and-submit (modo --no-dispatch) completado. Pendientes creados en BD: {$totalPendingCreated}. NO se encolo a Redis (encolado manual por scripts/transcription_enqueue_batch.php).");
            if ($cacheKey) {
                $status = ($failedStorages > 0 && $failedStorages < $storages->count()) ? 'partial' : 'queued';
                $message = null;
                if ($failedStorages > 0) {
                    $message = "{$failedStorages} de {$storages->count()} storages fallaron durante el scan. Ver per_storage_errors.";
                    if ($failedStorages === $storages->count()) {
                        $status = 'error';
                        $message = "Todos los storages ({$storages->count()}) fallaron durante el scan. Ver per_storage_errors.";
                    }
                }
                \Illuminate\Support\Facades\Cache::put($cacheKey, [
                    'status' => $status,
                    'batch' => 0,
                    'processed' => 0,
                    'errors' => $failedStorages,
                    'total_to_process' => 0,
                    'total_candidates' => $totalPendingCreated + $failedStats['reset_to_pending'],
                    'per_storage_errors' => $perStorageErrors,
                    'failed_recovered' => $failedStats['reset_to_pending'],
                    'failed_promoted_to_dead' => $failedStats['promoted_to_dead'],
                    'failed_skipped_max_retries' => $failedStats['skipped_max_retries'],
                    'message' => $message,
                    'started_at' => now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ], now()->addHours(2));
            }
            return Command::SUCCESS;
        }

        // Tope de encolado por ciclo. NO se multiplica por el numero de storages:
        // con 31 storages y scan_batch=100 eso eran 3100 jobs en un bucle apretado,
        // saltandose el regulador por completo. Es ademas la ruta que usa el boton
        // "Escanear storages" de la UI, asi que tambien inundaba con disparo manual.
        // Freno de emergencia: como en el tick, se comprueba despues del
        // descubrimiento — las filas pending ya estan creadas, solo no se encolan.
        if ($settings->bool('dispatch_paused')) {
            $this->warn('Dispatch omitido: dispatch_paused activo.');
            Log::info('ScanAndSubmitCommand: skip dispatch, dispatch_paused activo');
            $submitBatch = 0;
        } else {
            $submitBatch = $this->computeSubmitBatch($settings, $batchOverride);

            if ($submitBatch <= 0) {
                $this->info('Dispatch omitido: la cola Redis ya esta en/sobre target.');
                Log::info('ScanAndSubmitCommand: skip dispatch, queue at target');
            }
        }

        $pending = $submitBatch > 0
            ? Transcription::where('state', Transcription::STATE_PENDING)
                ->whereNull('job_id')
                ->limit($submitBatch)
                ->orderBy('created_at')
                ->get()
            : collect();

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
            $status = ($failedStorages > 0 && $failedStorages < $storages->count()) ? 'partial' : 'queued';
            $message = null;
            if ($failedStorages > 0) {
                $message = "{$failedStorages} de {$storages->count()} storages fallaron durante el scan. Ver per_storage_errors.";
                if ($failedStorages === $storages->count()) {
                    $status = 'error';
                    $message = "Todos los storages ({$storages->count()}) fallaron durante el scan. Ver per_storage_errors.";
                }
            }

            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => $status,
                'batch' => 0,
                'processed' => 0,
                'errors' => $errors + $failedStorages,
                'total_to_process' => $dispatched,
                'total_candidates' => $totalPendingCreated + $failedStats['reset_to_pending'],
                'per_storage_errors' => $perStorageErrors,
                'failed_recovered' => $failedStats['reset_to_pending'],
                'failed_promoted_to_dead' => $failedStats['promoted_to_dead'],
                'failed_skipped_max_retries' => $failedStats['skipped_max_retries'],
                'message' => $message,
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ], now()->addHours(2));
        }

        return Command::SUCCESS;
    }

    /**
     * Tope de jobs a encolar en este ciclo.
     *
     * Antes esto era scan_batch * count($storages) — con 31 storages, 3100 jobs
     * de golpe sin consultar al regulador. Ahora se acota por dos vias:
     *  1. scan_max_dispatch_per_cycle: techo absoluto por ejecucion.
     *  2. El deficit del regulador (misma aritmetica que TranscriptionTickCommand):
     *     si la cola Redis ya esta en/sobre target, devuelve 0.
     *
     * Asi el camino manual (boton "Escanear storages") nunca puede exceder al
     * automatico.
     */
    private function computeSubmitBatch(TranscriptorSettings $settings, ?int $batchOverride): int
    {
        $cap = $batchOverride ?? $settings->int('scan_max_dispatch_per_cycle');

        try {
            $current = (int) Redis::llen('queues:transcription');
        } catch (\Throwable $e) {
            // Sin visibilidad de la cola preferimos el techo conservador antes
            // que abortar el ciclo entero.
            Log::warning('ScanAndSubmitCommand: no se pudo leer llen(queues:transcription): ' . $e->getMessage());
            return $cap;
        }

        // Misma aritmetica que el tick: el camino manual nunca puede exceder al
        // automatico.
        return min($cap, $settings->computeDispatchBatch($current));
    }
}