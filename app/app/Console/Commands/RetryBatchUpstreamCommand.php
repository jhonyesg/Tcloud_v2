<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-encola en bloque los jobs fallidos (error|dead) en el upstream.
 *
 * Motivacion: el upstream expone `POST /v1/jobs/retry-batch` que re-envia
 * sin re-ffmpeg + re-upload usando el .bin aún en disco (ventana 24h).
 * Antes este comando no existía y el operador tenia que clickear el botón
 * "Reintentar" uno a uno sobre cada job fallido.
 *
 * Strategy:
 *  - Lista locales `state IN (error, dead)` con `job_id` no nulo y
 *    `created_at > now() - maxAgeHours`.
 *  - --dry-run: solo cuenta y agrupa por error_message; no llama al upstream.
 *  - Sin --dry-run: llama `TranscriptorApiClient::retryBatchUpstream(olderSeconds, limit)`
 *    y reporta los counters devueltos por la API.
 *
 * Uso:
 *   php artisan transcription:retry-batch-upstream --dry-run
 *   php artisan transcription:retry-batch-upstream --max-age-hours=168 --limit=500
 */
class RetryBatchUpstreamCommand extends Command
{
    protected $signature = 'transcription:retry-batch-upstream
                            {--dry-run : Solo contar, no enviar al upstream}
                            {--max-age-hours=48 : Solo jobs creados en este horizonte}
                            {--limit=500 : Cuantos como maximo}';

    protected $description = 'Re-encola en bloque los jobs error|dead via POST /v1/jobs/retry-batch';

    public function handle(TranscriptorApiClient $client): int
    {
        $maxAgeHours = (int) $this->option('max-age-hours');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Transcription::query()
            ->whereIn('state', [Transcription::STATE_ERROR, Transcription::STATE_DEAD])
            ->whereNotNull('job_id')
            ->where('created_at', '>=', now()->subHours($maxAgeHours))
            ->limit($limit);

        $count = (clone $candidates)->count();
        $byError = (clone $candidates)
            ->selectRaw('error_message, count(*) as c')
            ->groupBy('error_message')
            ->get()
            ->map(fn ($r) => [
                'error_message' => $r->error_message ?? '(null)',
                'count' => $r->c,
            ])
            ->all();

        $this->info("Candidatos (error|dead con job_id en {$maxAgeHours}h): {$count}");

        if ($count === 0) {
            $this->line('  Nada que procesar.');
            return self::SUCCESS;
        }

        $this->table(['Motivo', 'Cantidad'], $byError);

        if ($dryRun) {
            $this->info("Dry-run: no se llama al upstream.");
            return self::SUCCESS;
        }

        $olderThanSeconds = $maxAgeHours * 3600;
        $this->info("Llamando POST /v1/jobs/retry-batch older_than_seconds={$olderThanSeconds} limit={$limit}...");

        try {
            $resp = $client->retryBatchUpstream($olderThanSeconds, $limit);
        } catch (\Throwable $e) {
            $this->error("retry-batch upstream fallo: {$e->getMessage()}");
            Log::error("RetryBatchUpstreamCommand: {$e->getMessage()}");
            return self::FAILURE;
        }

        $requeued = (int) ($resp['requeued'] ?? 0);
        $skipped = (int) ($resp['skipped'] ?? 0);
        $failed = (int) ($resp['failed'] ?? 0);

        $this->info("Resultado:");
        $this->line("  requeued: {$requeued}");
        $this->line("  skipped:  {$skipped}");
        $this->line("  failed:   {$failed}");

        Log::info('RetryBatchUpstreamCommand result', [
            'candidates' => $count,
            'requeued' => $requeued,
            'skipped' => $skipped,
            'failed' => $failed,
            'max_age_hours' => $maxAgeHours,
        ]);

        return self::SUCCESS;
    }
}
