<?php

namespace App\Console\Commands;

use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptionProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanStaleJobsCommand extends Command
{
    protected $signature = 'transcription:scan-stale';
    protected $description = '[DEPRECATED] Usar transcription:poll-results en su lugar. Polling de respaldo para webhooks perdidos.';

    public function handle(): int
    {
        $this->warn('Comando DEPRECADO. Usar: php artisan transcription:poll-results');
        return Command::SUCCESS;
    }
}