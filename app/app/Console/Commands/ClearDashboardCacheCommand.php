<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * dashboard-tiered-cache: invalida manualmente el cache por tiers del
 * dashboard (frío + tibio) sin deploy.
 *
 * Uso:
 *   php artisan dashboard:clear-cache
 *
 * NO toca `coverage:dashboard` ni las keys administradas por
 * WatermarkReconciler/CacheEpoch — esas se invalidan solas vía bump del epoch.
 *
 * Cuándo ejecutarlo:
 *   - Tras una mutación masiva de datos que el operador quiere ver reflejada ya
 *   - Si el indicador de frescura muestra más antigüedad de la esperada
 */
class ClearDashboardCacheCommand extends Command
{
    protected $signature = 'dashboard:clear-cache';

    protected $description = 'Invalida el cache frío y tibio del dashboard';

    public function handle(): int
    {
        $forgot = [];

        foreach (['dashboard:cold:stats', 'dashboard:cold:media-editor'] as $key) {
            $this->forget($key, $forgot);
        }

        // El tier tibio incluye el epoch en la key; limpiar los últimos epochs
        // razonables cubre corridas recientes sin depender de SCAN.
        $epoch = \App\Services\Ia\CacheEpoch::get();
        foreach (range(max(0, $epoch - 5), $epoch + 1) as $e) {
            $this->forget('dashboard:warm:mis-avisos:e' . $e, $forgot);
        }

        foreach ($forgot as $key) {
            $this->line("  forgot {$key}");
        }

        $this->info('✓ Cache del dashboard invalidado (' . count($forgot) . ' keys).');
        $this->line('  La próxima carga de /dashboard recalcula los bloques frío y tibio.');

        return self::SUCCESS;
    }

    private function forget(string $key, array &$forgot): void
    {
        Cache::forget($key);
        Cache::forget('illuminate:cache:flexible:created:' . $key);
        $forgot[] = $key;
    }
}
