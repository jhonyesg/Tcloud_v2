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

    protected $description = '[DEPRECATED] Usar transcription:scan-and-submit --days=N en su lugar. Procesa un lote de archivos sin transcripcion.';

    public function handle(): int
    {
        $this->warn('Comando DEPRECADO. Usar: php artisan transcription:scan-and-submit --days=7');
        return Command::SUCCESS;
    }
}