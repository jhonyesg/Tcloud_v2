<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\EnEsMixMiner;
use Illuminate\Console\Command;

/**
 * Detecta mezclas EN↔ES en el corpus de transcripciones y propone las
 * correcciones como pending para revisión admin en `/ia/correcciones`.
 *
 * Solo estrategia KNOWN (mapeos hardcoded curados manualmente). La
 * antigua estrategia "open" fue retirada (ver change
 * 2026-08-15-en-es-mix-miner-prune-open-strategy) porque emitía
 * bigramas de 2 palabras que contradicen el umbral transversal
 * `corrections.min_suggestion_words=3`.
 *
 * Pensado para uso manual, no programado (el cron fue desprogramado el
 * 2026-08-11; ver `app/routes/console.php:113`).
 *
 * Uso:
 *   php artisan corrections:mine-en-es --days=30 --dry-run
 *   php artisan corrections:mine-en-es --days=14 --min-freq=5
 *
 * Sin --dry-run: invoca CorrectionService::mineEnEsMix(), que inserta
 * cada candidato como pending con source='mining-YYYY-MM-DD'.
 * Con --dry-run: solo muestra la tabla, no toca BD.
 */
class MineEnEsCorrectionsCommand extends Command
{
    protected $signature = 'corrections:mine-en-es
                            {--days=30 : Ventana de análisis en días}
                            {--min-freq=3 : Frecuencia mínima en el corpus para proponer}
                            {--dry-run : Solo muestra candidatos, no inserta}';

    protected $description = 'Detecta mezclas EN↔ES en las transcripciones y propone correcciones pending.';

    public function handle(CorrectionService $service): int
    {
        $days = max(1, (int) $this->option('days'));
        $minFreq = max(1, (int) $this->option('min-freq'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Mining EN↔ES: days={$days} min-freq={$minFreq}" . ($dryRun ? ' [DRY-RUN]' : ''));

        if ($dryRun) {
            $miner = new EnEsMixMiner();
            $candidates = $miner->mine($days, $minFreq);

            if (empty($candidates)) {
                $this->warn('Dry-run: 0 candidatos detectados. No se insertó nada.');
                return self::SUCCESS;
            }

            $this->table(
                ['Wrong', 'Correct', 'Freq', 'Strategy'],
                array_map(
                    fn($c) => [$c['wrong'], $c['correct'], $c['freq'], $c['strategy']],
                    $candidates
                )
            );
            $this->info('Dry-run: ' . count($candidates) . ' candidatos detectados. Usar sin --dry-run para insertar.');
            return self::SUCCESS;
        }

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if (!$admin) {
            $this->error('No se encontró usuario admin para asociar como proposed_by.');
            return self::FAILURE;
        }

        $result = $service->mineEnEsMix($days, $minFreq, $admin);

        $this->info("Mined: {$result['mined']}");
        $this->info("Inserted: {$result['inserted']}");
        $this->info("Skipped (pending duplicado): {$result['skipped_duplicate']}");
        $this->info("Source: {$result['source']}");

        return self::SUCCESS;
    }
}
