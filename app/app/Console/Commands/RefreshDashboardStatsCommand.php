<?php

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardStatsCache;
use Illuminate\Console\Command;

/**
 * Recalcula en background el agregado global de `files` que consume el
 * dashboard admin (change `dashboard-perf-backup-guide`, T5).
 *
 * Por qué: `COUNT(*) + SUM(size)` sobre 1,2 M filas es un seq scan de 237-737
 * ms. Hacerlo dentro del request que encuentra el cache frío es exactamente lo
 * que se percibía como "el dashboard siempre se demora". Con este comando el
 * costo se paga fuera del camino del usuario.
 *
 * Uso:
 *   php artisan dashboard:refresh-stats
 *   php artisan dashboard:refresh-stats --dry-run
 */
class RefreshDashboardStatsCommand extends Command
{
    protected $signature = 'dashboard:refresh-stats
                            {--dry-run : Mide el agregado sin publicarlo}';

    protected $description = 'Recalcula el agregado global de files para el dashboard admin';

    public function handle(DashboardStatsCache $cache): int
    {
        if ($this->option('dry-run')) {
            $started = microtime(true);
            $payload = $cache->refresh();

            // En dry-run se calculó y publicó; se revierte para no alterar el
            // estado observable por el operador que solo quería medir.
            $cache->forget();

            $this->info(sprintf(
                'Agregado: %s archivos · %.2f GB · calculado en %.0f ms (no publicado)',
                number_format($payload['total_files']),
                $payload['storage_used'] / 1073741824,
                (microtime(true) - $started) * 1000
            ));

            return self::SUCCESS;
        }

        $started = microtime(true);
        $payload = $cache->refresh();

        $this->info(sprintf(
            'Agregado publicado: %s archivos · %.2f GB · %.0f ms',
            number_format($payload['total_files']),
            $payload['storage_used'] / 1073741824,
            (microtime(true) - $started) * 1000
        ));

        return self::SUCCESS;
    }
}
