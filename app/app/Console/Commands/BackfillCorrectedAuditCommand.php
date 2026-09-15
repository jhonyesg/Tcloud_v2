<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-hidrata la columna `corrected` (y `corr_pass`, `corr_mms`) para los
 * jobs `state=done` con `corrected IS NULL`. Solo en BD local; no se re-
 * procesa el SRT ni las alertas.
 *
 * Motivación (Fase E): 3.969 jobs `done` están `corrected=0` esperando el
 * corrector async, 1.735 están `corrected=-1` (corrector falló). El operador
 * necesita ver esta diferencia en la UI, y el primer paso es hidratar el
 * campo para el histórico.
 *
 * Idempotente: el filtro `WHERE corrected IS NULL` solo trae los que
 * faltan. Se puede correr multiples veces sin duplicar.
 *
 * Sin --dry-run: llama `GET /v1/jobs/{id}` para cada uno. Limitado a `limit`
 * por corrida; corridas grandes se hacen con schedule semanal o manualmente.
 *
 * Uso:
 *   php artisan transcription:backfill-corrected-audit --dry-run
 *   php artisan transcription:backfill-corrected-audit --days=30 --limit=1000
 */
class BackfillCorrectedAuditCommand extends Command
{
    protected $signature = 'transcription:backfill-corrected-audit
                            {--dry-run : Solo contar, no llamar al upstream}
                            {--days=7 : Solo jobs creados en este horizonte}
                            {--limit=500 : Cuantos como maximo}';

    protected $description = 'Re-hidrata corrected, corr_pass, corr_mms para jobs done sin auditar';

    public function handle(TranscriptorApiClient $client): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $base = Transcription::query()
            ->where('state', Transcription::STATE_DONE)
            ->whereNull('corrected')
            ->whereNotNull('job_id')
            ->where('created_at', '>=', now()->subDays($days));

        $count = (clone $base)->count();
        $byDate = (clone $base)
            ->selectRaw('date_trunc(\'day\', created_at) AS day, COUNT(*) AS c')
            ->groupBy('day')
            ->orderBy('day', 'desc')
            ->limit(30)
            ->get()
            ->map(fn ($r) => ['Dia' => substr($r->day, 0, 10), 'Cantidad' => $r->c])
            ->all();

        $this->info("Por auditar ({$days}d, limit={$limit}): {$count}");
        if ($byDate) {
            $this->table(['Dia', 'Cantidad'], $byDate);
        }

        if ($count === 0) {
            $this->line('  Nada que auditar.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry-run: no se hacen llamadas.");
            return self::SUCCESS;
        }

        $updated = 0;
        $errors = 0;
        foreach ((clone $base)->limit($limit)->get() as $tx) {
            try {
                $remote = $client->getJob($tx->job_id, $tx->node_url ?? '');
                $tx->update([
                    'corrected' => $remote['corrected'] ?? null,
                    'corr_pass' => $remote['corr_pass'] ?? null,
                    'corr_mms' => $remote['corr_mms'] ?? null,
                    'last_polled_at' => now(),
                ]);
                $updated++;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning("BackfillCorrectedAudit tx={$tx->id} job={$tx->job_id} fallo: {$e->getMessage()}");
            }
        }

        $this->info("Actualizados: {$updated} · Errores: {$errors}");

        Log::info('BackfillCorrectedAudit result', [
            'updated' => $updated,
            'errors' => $errors,
            'limit' => $limit,
            'days' => $days,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
