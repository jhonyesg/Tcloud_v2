<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purga snapshots de transcription_storage_snapshots con mas de N dias.
 *
 * Default: 7 dias. Override con --days=N.
 *
 * Loopea en chunks de 10000 para evitar lock prolongado de la tabla cuando
 * hay backlog de purga (ej. tras una corrida atrasada o un cambio de retention).
 */
class TranscriptionPruneSnapshotsCommand extends Command
{
    protected $signature = 'transcriptor:prune-storage-snapshots
                            {--days=7 : Borrar snapshots con captured_at anterior a NOW() - N dias}
                            {--chunk=10000 : Tamano del chunk por iteracion}
                            {--dry-run : Solo contar, no borrar}';

    protected $description = 'Purga snapshots del transcriptor con mas de N dias (default 7).';

    public function handle(): int
    {
        // Permitir --days=0 (purgar todo, util para tests). Solo protegemos contra negativos.
        $days = max(0, (int) $this->option('days'));
        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            if ($dryRun) {
                $count = (int) DB::table('transcription_storage_snapshots')
                    ->where('captured_at', '<', $cutoff)
                    ->limit($chunk)
                    ->count();
                if ($count === 0) {
                    break;
                }
                $this->line("[DRY-RUN] Se borrarian {$count} filas (cutoff={$cutoff->toIso8601String()})");
                $totalDeleted += $count;
                break;
            }

            // Seleccionar el lote de pares (storage_provider_id, captured_at)
            // a borrar. La PK compuesta nos da unicidad y el chunk limita el
            // tamano de la operacion para no lockear la tabla en backlogs
            // grandes. Luego DELETE directo con WHERE sobre la PK funciona
            // de forma portable en PostgreSQL.
            $rows = DB::table('transcription_storage_snapshots')
                ->where('captured_at', '<', $cutoff)
                ->limit($chunk)
                ->get(['storage_provider_id', 'captured_at']);

            if ($rows->isEmpty()) {
                break;
            }

            $deleted = 0;
            foreach ($rows as $row) {
                $deleted += DB::table('transcription_storage_snapshots')
                    ->where('storage_provider_id', $row->storage_provider_id)
                    ->where('captured_at', $row->captured_at)
                    ->delete();
            }
            $totalDeleted += $deleted;
        } while (true);

        if ($totalDeleted > 0) {
            Log::info('transcriptor.prune_storage_snapshots.done', [
                'days' => $days,
                'deleted' => $totalDeleted,
                'dry_run' => $dryRun,
            ]);
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Snapshots purgados: {$totalDeleted} (cutoff={$cutoff->toIso8601String()})");

        return Command::SUCCESS;
    }
}