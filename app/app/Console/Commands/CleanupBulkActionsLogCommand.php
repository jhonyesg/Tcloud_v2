<?php

namespace App\Console\Commands;

use App\Models\CorrectionBulkAction;
use Illuminate\Console\Command;

class CleanupBulkActionsLogCommand extends Command
{
    protected $signature = 'corrections:cleanup-undo-log
                            {--dry-run : Solo reporta, no borla}';

    protected $description = 'Purga entradas de correction_bulk_actions más viejas que la retención configurada (default 7 días).';

    public function handle(): int
    {
        $retentionDays = (int) config('corrections.undo_log_retention_days', 7);
        $cutoff = now()->subDays($retentionDays);

        $query = CorrectionBulkAction::where('expires_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Dry-run: se borrarían {$count} bulk_actions con expires_at < {$cutoff->toIso8601String()}");
            return self::SUCCESS;
        }

        $deleted = $query->delete(); // CASCADE borra items
        $this->info("Limpiados {$deleted} bulk_actions (retention={$retentionDays}d, cutoff={$cutoff->toIso8601String()})");
        return self::SUCCESS;
    }
}