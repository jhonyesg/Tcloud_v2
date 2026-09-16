<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * avisos-keyword-storage-watermark: comando administrativo para reinicializar
 * los contadores candidates_total / hits_total de la tabla de watermarks.
 *
 * Uso: php artisan avisos:reset-watermark-counters [--dry-run]
 *
 * No es urgente: los contadores son unsignedBigInteger (techo 9.2e18) y crecen
 * ~50/día. Documentado en design.md riesgo #5.
 */
class ResetWatermarkCountersCommand extends Command
{
    protected $signature = 'avisos:reset-watermark-counters {--dry-run : Solo mostrar cuántos pares se afectarían}';
    protected $description = 'Reinicia los contadores candidates_total y hits_total de keyword_scan_watermarks';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $count = DB::table('keyword_scan_watermarks')->count();
        $this->line("Pares en keyword_scan_watermarks: {$count}");

        if ($dryRun) {
            $this->info('Dry-run — nada modificado.');
            return self::SUCCESS;
        }

        if (!$this->confirm('¿Reiniciar contadores (candidates_total=0, hits_total=0)?', false)) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        $updated = DB::table('keyword_scan_watermarks')
            ->update([
                'candidates_total' => 0,
                'hits_total' => 0,
                'updated_at' => now(),
            ]);

        $this->info("Reiniciado: {$updated} filas.");
        return self::SUCCESS;
    }
}
