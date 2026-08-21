<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptionPollingService;
use App\Services\Ia\TranscriptionSubmitService;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollResultsCommand extends Command
{
    protected $signature = 'transcription:poll-results';

    protected $description = 'Polling de respaldo: recupera resultados de transcripciones queued/processing vía GET /v1/jobs/{id} y reenvía pendientes atascados.';

    public function handle(TranscriptionPollingService $polling, TranscriptionSubmitService $submitter, TranscriptorSettings $settings): int
    {
        // 1. Reenviar pendientes sin job_id que excedan stale_after_minutes.
        //
        // Cada reenvio corre ffmpeg + POST SINCRONOS en este mismo proceso, cada
        // minuto, en paralelo con el pool de workers. El limite estaba
        // hardcodeado en 50 y fue lo que disparo el pico del 2026-07-24 11:35;
        // ahora es ajustable en caliente (stale_resend_limit).
        $staleAfter = $settings->int('stale_after_minutes');
        $resendLimit = $settings->int('stale_resend_limit');

        $stuck = $resendLimit > 0
            ? Transcription::where('state', Transcription::STATE_PENDING)
                ->whereNull('job_id')
                ->where('created_at', '<', now()->subMinutes($staleAfter))
                ->limit($resendLimit)
                ->get()
            : collect();

        $recoveredPending = 0;
        foreach ($stuck as $tx) {
            $result = $submitter->submit($tx);
            if ($result['ok']) {
                $recoveredPending++;
            } else {
                Log::error("poll-results: no se pudo reenviar pending {$tx->id}: {$result['error']}");
            }
        }

        // 2. Polling de queued/processing con job_id.
        $stats = $polling->pollAll();

        $this->info(sprintf(
            'Poll-results: pendientes recuperados=%d, polled=%d, done=%d, errors=%d, still_pending=%d, lost=%d, aged_out=%d',
            $recoveredPending,
            $stats['polled'],
            $stats['done'],
            $stats['errors'],
            $stats['still_pending'],
            $stats['lost'],
            $stats['aged_out'],
        ));

        // Las filas cerradas por perdida upstream son recuperables re-enviando
        // el audio: transcription:backfill-lost. Se registra a nivel WARNING
        // para que quede rastro con LOG_LEVEL=warning.
        if ($stats['lost'] > 0 || $stats['aged_out'] > 0) {
            Log::warning('poll-results: transcripciones cerradas sin resultado', [
                'lost' => $stats['lost'],
                'aged_out' => $stats['aged_out'],
                'accion' => 'php artisan transcription:backfill-lost --audit',
            ]);
        }

        return Command::SUCCESS;
    }
}