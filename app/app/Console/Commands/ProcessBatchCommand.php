<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessBatchCommand extends Command
{
    protected $signature = 'transcription:process-batch
                            {--batch=50 : Numero maximo de archivos a procesar}
                            {--run-id= : Identificador unico para el progreso en cache}
                            {--alerts=0 : 1=generar alertas, 0=no generar}';
    protected $description = 'Procesa un lote de archivos sin transcripcion, distribuido por prioridad de storage. Ejecuta en background y reporta progreso via cache.';

    public function handle(): int
    {
        $batch = max(1, min(200, (int) $this->option('batch')));
        $runId = $this->option('run-id') ?: ('batch_' . time());
        $cacheKey = 'transcription_batch:' . $runId;
        $generateAlerts = (bool) (int) $this->option('alerts');
        $minAge = (int) config('transcriptor.scan_min_age_seconds', 60);
        $cutoff = now()->subSeconds($minAge);

        // 1. Storages habilitados por prioridad.
        $storages = StorageProvider::where('transcription_enabled', true)
            ->orderByDesc('transcription_priority')
            ->orderBy('name')
            ->get(['id', 'name', 'transcription_priority']);

        if ($storages->isEmpty()) {
            $this->writeProgress($cacheKey, ['status' => 'done', 'message' => 'No hay storages habilitados', 'processed' => 0, 'errors' => 0, 'total_candidates' => 0, 'storages' => [], 'files' => [], 'batch' => $batch]);
            return Command::SUCCESS;
        }

        // 2. Contar candidatos por storage.
        $candidatesByStorage = [];
        $totalCandidates = 0;
        foreach ($storages as $s) {
            $count = File::where('storage_provider_id', $s->id)
                ->where('is_folder', false)
                ->where('file_modified_at', '<', $cutoff)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('transcriptions')
                      ->whereColumn('transcriptions.file_id', 'files.id');
                })
                ->count();
            $candidatesByStorage[$s->id] = $count;
            $totalCandidates += $count;
        }

        if ($totalCandidates === 0) {
            $this->writeProgress($cacheKey, ['status' => 'done', 'message' => 'No hay archivos pendientes', 'processed' => 0, 'errors' => 0, 'total_candidates' => 0, 'storages' => [], 'files' => [], 'batch' => $batch]);
            return Command::SUCCESS;
        }

        // 3. Distribuir el lote con peso por prioridad.
        $quotaByStorage = [];
        $remaining = $batch;
        foreach ($storages as $s) {
            $cand = $candidatesByStorage[$s->id];
            if ($cand === 0) { $quotaByStorage[$s->id] = 0; continue; }
            $weight = $cand * (1 + ($s->transcription_priority / 10));
            $quotaByStorage[$s->id] = $weight;
        }
        $totalWeight = array_sum($quotaByStorage);
        $allocated = [];
        foreach ($storages as $s) {
            if ($quotaByStorage[$s->id] === 0) { $allocated[$s->id] = 0; continue; }
            $q = (int) round($batch * ($quotaByStorage[$s->id] / $totalWeight));
            $q = max(1, min($q, $candidatesByStorage[$s->id], $remaining));
            $allocated[$s->id] = $q;
            $remaining -= $q;
        }
        if ($remaining > 0) {
            foreach ($storages as $s) {
                if ($remaining <= 0) break;
                $canAdd = min($remaining, $candidatesByStorage[$s->id] - $allocated[$s->id]);
                if ($canAdd > 0) { $allocated[$s->id] += $canAdd; $remaining -= $canAdd; }
            }
        }

        // Calcular total a procesar para la barra de progreso.
        $totalToProcess = array_sum($allocated);

        // 4. Estado inicial en cache.
        $this->writeProgress($cacheKey, [
            'status' => 'running',
            'batch' => $batch,
            'total_candidates' => $totalCandidates,
            'total_to_process' => $totalToProcess,
            'processed' => 0,
            'errors' => 0,
            'current_index' => 0,
            'current_file' => null,
            'current_storage' => null,
            'storages' => [],
            'files' => [],
            'started_at' => now()->toIso8601String(),
        ]);

        // 5. Ejecutar cada storage secuencialmente.
        $results = [];
        $totalProcessed = 0;
        $totalErrors = 0;
        $fileResults = [];
        $currentIdx = 0;

        foreach ($storages as $s) {
            $quota = $allocated[$s->id] ?? 0;
            if ($quota <= 0) {
                $results[] = ['storage_id' => $s->id, 'name' => $s->name, 'priority' => $s->transcription_priority, 'candidates' => $candidatesByStorage[$s->id], 'quota' => 0, 'processed' => 0, 'errors' => 0];
                continue;
            }

            $files = File::where('storage_provider_id', $s->id)
                ->where('is_folder', false)
                ->where('file_modified_at', '<', $cutoff)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('transcriptions')
                      ->whereColumn('transcriptions.file_id', 'files.id');
                })
                ->orderByDesc('file_modified_at')
                ->limit($quota)
                ->get(['id', 'name']);

            $processed = 0;
            $errors = 0;
            foreach ($files as $file) {
                $currentIdx++;
                $fr = ['file_id' => $file->id, 'name' => $file->name, 'storage' => $s->name, 'ok' => false, 'error' => null];

                // Actualizar progreso en cache antes de procesar.
                $this->updateProgress($cacheKey, [
                    'current_index' => $currentIdx,
                    'current_file' => $file->name,
                    'current_storage' => $s->name,
                ]);

                try {
                    $priority = \App\Jobs\ConvertAndTranscribeJob::calculatePriority(
                        (int) $s->transcription_priority, false, true  // histórico, manual
                    );
                    \App\Jobs\ConvertAndTranscribeJob::dispatchWithPriority($file->id, $generateAlerts, $priority);
                    $processed++;
                    $fr['ok'] = true;
                } catch (\Throwable $e) {
                    Log::error("process-batch file {$file->id}: {$e->getMessage()}");
                    $errors++;
                    $fr['error'] = $e->getMessage();
                }
                $fileResults[] = $fr;
                $totalProcessed++;
                $totalErrors += $errors - ($fr['ok'] ? 0 : 0);

                // Actualizar contadores en cache tras cada archivo.
                $this->updateProgress($cacheKey, [
                    'processed' => $totalProcessed,
                    'errors' => $totalErrors,
                ]);
            }

            $results[] = [
                'storage_id' => $s->id,
                'name' => $s->name,
                'priority' => $s->transcription_priority,
                'candidates' => $candidatesByStorage[$s->id],
                'quota' => $quota,
                'processed' => $processed,
                'errors' => $errors,
            ];
        }

        // 6. Estado final.
        $this->writeProgress($cacheKey, [
            'status' => 'done',
            'batch' => $batch,
            'total_candidates' => $totalCandidates,
            'total_to_process' => $totalToProcess,
            'processed' => $totalProcessed,
            'errors' => $totalErrors,
            'current_index' => $currentIdx,
            'current_file' => null,
            'current_storage' => null,
            'storages' => $results,
            'files' => $fileResults,
            'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        // Expirar el cache en 1 hora para que el frontend pueda leer el resultado final.
        Cache::put($cacheKey, $this->readProgress($cacheKey), now()->addHour());

        $this->info("Lote completado. Procesados: {$totalProcessed}, Errores: {$totalErrors} de {$totalToProcess}.");
        return Command::SUCCESS;
    }

    private function writeProgress(string $key, array $data): void
    {
        Cache::put($key, array_merge($data, ['updated_at' => now()->toIso8601String()]), now()->addHours(2));
    }

    private function updateProgress(string $key, array $partial): void
    {
        $current = Cache::get($key, []);
        Cache::put($key, array_merge($current, $partial, ['updated_at' => now()->toIso8601String()]), now()->addHours(2));
    }

    private function readProgress(string $key): array
    {
        return Cache::get($key, []);
    }
}