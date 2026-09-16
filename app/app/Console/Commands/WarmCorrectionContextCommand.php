<?php

namespace App\Console\Commands;

use App\Models\Correction;
use App\Services\Ia\CorrectionContextFinder;
use Illuminate\Console\Command;

/**
 * Precalienta la caché de ejemplos de contexto que consume el modal
 * "Ver ejemplos" de `/ia/correcciones`.
 *
 * La búsqueda va contra transcription_segments (8,3 GB) y tarda entre 0,2 s y
 * varios segundos según lo frecuente que sea el término. Corriendo esto de noche
 * sobre la cola de pendientes —que son decenas, no miles— el admin se encuentra
 * el modal instantáneo al revisarlas por la mañana.
 *
 * Uso:
 *   php artisan corrections:warm-context
 *   php artisan corrections:warm-context --status=approved --limit=200
 */
class WarmCorrectionContextCommand extends Command
{
    protected $signature = 'corrections:warm-context
                            {--status=pending : Estado a precalentar: pending|approved|rejected}
                            {--limit=100 : Máximo de correcciones a procesar}';

    protected $description = 'Precalienta la caché de ejemplos de contexto de las correcciones.';

    public function handle(CorrectionContextFinder $finder): int
    {
        $status = (string) $this->option('status');
        $limit = max(1, (int) $this->option('limit'));

        $allowed = [Correction::STATUS_PENDING, Correction::STATUS_APPROVED, Correction::STATUS_REJECTED];

        if (!in_array($status, $allowed, true)) {
            $this->error("--status debe ser uno de: " . implode(', ', $allowed) . ". Recibido: {$status}");

            return self::FAILURE;
        }

        $corrections = Correction::query()
            ->where('status', $status)
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($corrections->isEmpty()) {
            $this->info("No hay correcciones con status={$status}.");

            return self::SUCCESS;
        }

        $this->info("Precalentando contexto de {$corrections->count()} correcciones (status={$status})…");

        $tally = [];
        $bar = $this->output->createProgressBar($corrections->count());
        $bar->start();

        foreach ($corrections as $correction) {
            // Deliberadamente secuencial y sin reintentos: el objetivo es llenar
            // la caché sin competir con el tráfico normal contra la misma tabla.
            $result = $finder->examples($correction);
            $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($tally as $resultStatus => $count) {
            $this->line("  {$resultStatus}: {$count}");
        }

        return self::SUCCESS;
    }
}
