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

        $this->info("Poll-results: pendientes recuperados={$recoveredPending}, polled={$stats['polled']}, done={$stats['done']}, errors={$stats['errors']}, still_pending={$stats['still_pending']}");
        return Command::SUCCESS;
    }
}