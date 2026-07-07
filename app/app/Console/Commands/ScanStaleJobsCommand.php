<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptionProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanStaleJobsCommand extends Command
{
    protected $signature = 'transcription:scan-stale';
    protected $description = 'Polling de respaldo: recupera transcripciones queued/processing > 30 min sin webhook.';

    public function handle(TranscriptorApiClient $client, TranscriptionProcessor $processor): int
    {
        $staleAfter = (int) config('transcriptor.stale_after_minutes', 30);
        $cutoff = now()->subMinutes($staleAfter);

        // 1. Recuperar jobs pending SIN job_id (el worker murió antes de enviar a la API).
        $pendingStuck = Transcription::where('state', Transcription::STATE_PENDING)
            ->whereNull('job_id')
            ->where('created_at', '<', now()->subMinutes(5))
            ->limit(50)
            ->get();

        $recoveredPending = 0;
        foreach ($pendingStuck as $transcription) {
            try {
                $storage = $transcription->file?->storageProvider;
                $storagePriority = (int) ($storage?->transcription_priority ?? 0);
                $isToday = $transcription->file?->file_modified_at?->isToday() ?? false;
                $priority = \App\Jobs\ConvertAndTranscribeJob::calculatePriority($storagePriority, $isToday, true);
                \App\Jobs\ConvertAndTranscribeJob::dispatchWithPriority($transcription->file_id, (bool) $transcription->generate_alerts, $priority);
                $recoveredPending++;
            } catch (\Throwable $e) {
                Log::error("scan-stale: no se pudo reencolar pending {$transcription->id}: {$e->getMessage()}");
            }
        }

        // 2. Recuperar jobs queued/processing CON job_id (webhook perdido).
        $stale = Transcription::whereIn('state', [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING])
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('job_id')
            ->limit(50)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No hay jobs stale.');
            return Command::SUCCESS;
        }

        $recovered = 0;
        foreach ($stale as $transcription) {
            try {
                $job = $client->getJob($transcription->job_id, $transcription->node_url ?? '');
                $state = $job['state'] ?? $job['status'] ?? null;

                if ($state === 'done') {
                    $processor->processDone($transcription->fresh());
                    $recovered++;
                } elseif ($state === 'error' || $state === 'dead') {
                    $processor->markError($transcription, $state, $job['error_message'] ?? 'dead in upstream');
                    $this->warn("Transcription {$transcription->id} marcada como {$state}.");
                } else {
                    $transcription->update(['state' => $state ?? $transcription->state]);
                }
            } catch (\Throwable $e) {
                Log::error("scan-stale: fallo consultando job {$transcription->id}: {$e->getMessage()}");
                $this->error("Transcription {$transcription->id}: {$e->getMessage()}");
            }
        }

        $this->info("Scan-stale completado. Pending recuperados: {$recoveredPending}. Done recuperados: {$recovered} de {$stale->count()}.");
        return Command::SUCCESS;
    }
}