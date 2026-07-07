<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\File;
use App\Models\StorageProvider;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanNewRecordingsCommand extends Command
{
    protected $signature = 'transcription:scan-new';
    protected $description = 'Escanea storages con transcription_enabled y encola jobs para archivos nuevos sin transcripción.';

    public function handle(): int
    {
        $minAge = (int) config('transcriptor.scan_min_age_seconds', 60);
        $batch = (int) config('transcriptor.scan_batch', 5);

        $storages = StorageProvider::transcriptionEnabled()->get();

        if ($storages->isEmpty()) {
            $this->info('No hay storages con transcripción habilitada.');
            return Command::SUCCESS;
        }

        $dispatched = 0;
        $cutoff = now()->subSeconds($minAge);
        $todayStart = now()->startOfDay();

        foreach ($storages as $storage) {
            // Archivos (no carpetas) del storage, SOLO DE HOY, sin Transcription,
            // con file_modified_at > 60s (archivo completo).
            $files = File::where('storage_provider_id', $storage->id)
                ->where('is_folder', false)
                ->where('file_modified_at', '>=', $todayStart)
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('file_modified_at')
                      ->orWhere('file_modified_at', '<', $cutoff);
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('transcriptions')
                      ->whereColumn('transcriptions.file_id', 'files.id');
                })
                ->orderByDesc('file_modified_at')
                ->limit($batch)
                ->get();

            foreach ($files as $file) {
                try {
                    $priority = \App\Jobs\ConvertAndTranscribeJob::calculatePriority(
                        (int) $storage->transcription_priority,
                        true,  // es de hoy
                        false  // es automático
                    );
                    \App\Jobs\ConvertAndTranscribeJob::dispatchWithPriority($file->id, true, $priority);
                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error("scan-new: no se pudo encolar file {$file->id}: {$e->getMessage()}");
                    $this->error("File {$file->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Scan-new completado. Jobs despachados: {$dispatched}");
        return Command::SUCCESS;
    }
}