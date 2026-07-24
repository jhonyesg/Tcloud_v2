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
    protected $description = '[DEPRECATED] Usar transcription:scan-and-submit en su lugar. Escanea storages y encola jobs para archivos nuevos.';

    public function handle(): int
    {
        $this->warn('Comando DEPRECADO. Usar: php artisan transcription:scan-and-submit');
        return Command::SUCCESS;
    }
}