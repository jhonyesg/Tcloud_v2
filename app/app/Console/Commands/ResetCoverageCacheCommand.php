<?php

namespace App\Console\Commands;

use App\Services\Ia\CacheEpoch;
use Illuminate\Console\Command;

/**
 * avisos-scan-coverage-admin-discoverability: comando para invalidar manualmente
 * la cache de coveragePaginated después de mutaciones directas en BD.
 *
 * Uso:
 *   php artisan avisos:reset-cache-coverage
 *
 * Sin opciones. Idempotente. NO toca la cache de audit log ni de full-scan.
 *
 * Cuándo ejecutarlo:
 *   - Tras INSERT/UPDATE directo en keyword_scan_watermarks por scripts SQL
 *   - Tras migraciones manuales
 *   - Si la UI muestra estado stale sin razón aparente
 *
 * Si la cache se siente stale en producción, este comando es el "reset".
 */
class ResetCoverageCacheCommand extends Command
{
    protected $signature = 'avisos:reset-cache-coverage';
    protected $description = 'Invalida la cache de coveragePaginated incrementando CacheEpoch (admin only)';

    public function handle(): int
    {
        $before = CacheEpoch::get();
        $after = CacheEpoch::bump();

        $this->info("CacheEpoch: {$before} → {$after}");
        $this->line('✓ Cache de coveragePaginated invalidada.');
        $this->line('  Próximo GET a /scan/coverage verá datos frescos de keyword_scan_watermarks.');
        return self::SUCCESS;
    }
}
