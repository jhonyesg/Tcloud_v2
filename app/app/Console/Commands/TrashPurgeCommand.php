<?php

namespace App\Console\Commands;

use App\Modules\Papelera\Services\PapeleraService;
use Illuminate\Console\Command;

/**
 * Purga diaria de items de papelera que superaron retention_days.
 *
 * Sale con codigo 0 incluso si aborta por guardarrail de ratio: queremos
 * que el scheduler no marque la tarea como failed (es un comportamiento
 * defensivo, no un error). Si hay una excepcion no manejada, sale con 1.
 *
 * Schedule: ver app/routes/console.php (dailyAt 03:17 sin solapamiento).
 */
class TrashPurgeCommand extends Command
{
    protected $signature = 'trash:purge {--batch= : tamano del chunk (default config trash.purge_batch_size)}
                                     {--max-ratio= : ratio maximo candidatos/total (default config trash.purge_max_ratio)}
                                     {--dry-run : cuenta candidatos sin borrar (no toca BD ni disco)}';

    protected $description = 'Purga items de papelera que superaron retention_days. Respeta guardarrail de ratio. --dry-run cuenta sin borrar.';

    public function handle(PapeleraService $service): int
    {
        $batch = (int) ($this->option('batch') ?? config('trash.purge_batch_size', 500));
        $maxRatio = (float) ($this->option('max-ratio') ?? config('trash.purge_max_ratio', 0.5));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            "trash:purge starting (batch=%d, max_ratio=%s, retention=%dd%s)",
            $batch,
            $maxRatio,
            (int) config('trash.retention_days', 15),
            $dryRun ? ', DRY-RUN' : ''
        ));

        try {
            $deleted = $service->purgeExpired($batch, $maxRatio, $dryRun);
        } catch (\Throwable $e) {
            $this->error('trash:purge failed: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error('papelera.purge.unhandled_exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        $this->info(sprintf(
            "trash:purge completed: %s=%d",
            $dryRun ? 'would_delete' : 'deleted',
            $deleted
        ));
        return self::SUCCESS;
    }
}
