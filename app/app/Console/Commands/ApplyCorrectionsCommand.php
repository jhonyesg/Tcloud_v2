<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated Use `corrections:apply-run --run-id=<id>` instead. This facade
 * stays as a manual alias for one release cycle to avoid breaking operators
 * that already use `transcription:apply-corrections` from CLI.
 */
class ApplyCorrectionsCommand extends Command
{
    protected $signature = 'transcription:apply-corrections
                            {--dry-run : Solo reporta cambios sin tocar la BD}
                            {--chunk=0 : Tamaño del chunk de segments por transacción (0 = usar corrections_chunk)}';

    protected $description = 'DEPRECATED: usa `corrections:apply-run --run-id=<id>`. Reaplica el diccionario de correcciones approved a todos los TranscriptionSegment existentes.';

    public function handle(): int
    {
        $runId = 'cli_' . time() . '_' . md5((string) mt_rand());
        $dryRun = (bool) $this->option('dry-run');
        // 0 se pasa tal cual para que corrections:apply-run resuelva el default
        // desde corrections_chunk en vez de fijar 500 aqui.
        $chunkOption = (int) $this->option('chunk');
        $chunk = $chunkOption > 0 ? max(50, $chunkOption) : 0;

        $this->warn("DEPRECATED: usa `corrections:apply-run --run-id=<id>` para getionar progreso.");

        return $this->call('corrections:apply-run', [
            '--run-id' => $runId,
            '--chunk' => $chunk,
            '--dry-run' => $dryRun,
        ]);
    }
}
