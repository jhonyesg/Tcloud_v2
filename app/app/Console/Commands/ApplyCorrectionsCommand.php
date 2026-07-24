<?php

namespace App\Console\Commands;

use App\Services\Ia\CorrectionService;
use Illuminate\Console\Command;

class ApplyCorrectionsCommand extends Command
{
    protected $signature = 'transcription:apply-corrections
                            {--dry-run : Solo reporta cambios sin tocar la BD}
                            {--chunk=500 : Tamaño del chunk de segments por transacción}';

    protected $description = 'Reaplica el diccionario de correcciones approved a todos los TranscriptionSegment existentes.';

    public function handle(CorrectionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $this->info($dryRun ? 'Modo dry-run: no se modificará la BD.' : 'Aplicando correcciones...');

        $progressBar = $this->output->createProgressBar();
        $progressBar->start();

        $last = 0;
        $updated = $service->applyRetroactively(
            function ($current) use ($progressBar, &$last) {
                if ($current !== $last) {
                    $progressBar->advance();
                    $last = $current;
                }
            },
            $chunk,
            $dryRun
        );

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry-run: {$updated} segments serían modificados con el diccionario actual.");
        } else {
            $this->info("Listo. {$updated} segments actualizados.");
        }

        return Command::SUCCESS;
    }
}