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
                                     {--max-ratio= : ratio maximo candidatos/total (default config trash.purge_max_ratio)}';

    protected $description = 'Purga items de papelera que superaron retention_days. Respeta guardarrail de ratio.';

    public function handle(PapeleraService $service): int
    {
        $batch = (int) ($this->option('batch') ?? config('trash.purge_batch_size', 500));
        $maxRatio = (float) ($this->option('max-ratio') ?? config('trash.purge_max_ratio', 0.5));

        $this->info("trash:purge starting (batch={$batch}, max_ratio={$maxRatio}, retention=" . config('trash.retention_days', 15) . "d)");

        try {
            $deleted = $service->purgeExpired($batch, $maxRatio);
        } catch (\Throwable $e) {
            $this->error('trash:purge failed: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error('papelera.purge.unhandled_exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        $this->info("trash:purge completed: deleted={$deleted}");
        return self::SUCCESS;
    }
}
