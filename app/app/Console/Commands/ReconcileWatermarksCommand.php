<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * avisos-scan-coverage-reconciler: comando manual para detectar y reparar
 * drift en keyword_scan_watermarks.
 *
 * Uso:
 *   php artisan avisos:reconcile-watermarks --dry-run
 *   php artisan avisos:reconcile-watermarks --user=5
 *   php artisan avisos:reconcile-watermarks --user=5 --dry-run
 *
 * Por defecto corre en modo "live" (repara drift negativo). Con --dry-run
 * sólo reporta.
 */
class ReconcileWatermarksCommand extends Command
{
    protected $signature = 'avisos:reconcile-watermarks
        {--user= : Acotar a un usuario específico}
        {--dry-run : Solo mostrar el reporte, no reparar}';

    protected $description = 'Detecta y repara drift en keyword_scan_watermarks';

    public function handle(): int
    {
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $dryRun = (bool) $this->option('dry-run');

        $reconciler = app(\App\Services\Ia\WatermarkReconciler::class);
        $report = $reconciler->driftReport($userId);

        $this->line('=== Drift Report ===');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Usuarios con acceso aplicables', $report['summary']['applicable_pairs']],
                ['Pares existentes en keyword_scan_watermarks', $report['summary']['existing_pairs']],
                ['Drift negativo (pares faltantes)', $report['summary']['missing']],
                ['Drift positivo (huérfanos)', $report['summary']['orphan']],
                ['Acotado a user_id', $report['summary']['scope_user_id'] ?? 'NO (sistema completo)'],
            ],
        );

        if ($dryRun) {
            $this->info('Dry-run: nada modificado.');
            if (!empty($report['missing'])) {
                $this->line('Primeros pares faltantes:');
                foreach (array_slice($report['missing'], 0, 10) as $m) {
                    $this->line("  - keyword={$m->keyword_id} storage={$m->storage_provider_id}");
                }
                if (count($report['missing']) > 10) {
                    $this->line('  ... y ' . (count($report['missing']) - 10) . ' más');
                }
            }
            return self::SUCCESS;
        }

        if ($report['summary']['missing'] === 0) {
            $this->info('Sin drift negativo — nada que reparar.');
            return self::SUCCESS;
        }

        $this->info("Reparando {$report['summary']['missing']} pares faltantes...");
        $totalCreated = 0;
        foreach ($report['missing'] as $miss) {
            $totalCreated += $reconciler->ensureForKeyword((int) $miss->keyword_id);
        }

        $this->info("Creados: {$totalCreated} watermarks.");
        return self::SUCCESS;
    }
}
