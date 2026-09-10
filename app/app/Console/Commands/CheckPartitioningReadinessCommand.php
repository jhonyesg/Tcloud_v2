<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * avisos-scan-coverage-completion: comando proactivo para alertar al admin
 * cuando las tablas grandes están cerca o superan los thresholds de
 * particionamiento.
 *
 * Thresholds:
 *  - watermark_audit_log: 100,000 filas (particionar por mes)
 *  - segment_keyword_hits: 10,000,000 filas (particionar por mes)
 *
 * Estados:
 *  - OK:           0%   a 70% del threshold
 *  - WARNING:      70%  a 100% del threshold
 *  - CRITICAL:   > 100% del threshold (particionar YA)
 */
class CheckPartitioningReadinessCommand extends Command
{
    protected $signature = 'avisos:check-partitioning-readiness';
    protected $description = 'Reporta el tamaño y readiness de particionamiento de las tablas grandes del módulo de avisos';

    private const THRESHOLDS = [
        'watermark_audit_log' => 100_000,
        'segment_keyword_hits' => 10_000_000,
    ];

    public function handle(): int
    {
        $rows = [];
        foreach (self::THRESHOLDS as $table => $threshold) {
            $count = (int) DB::table($table)->count();
            $pct = round(($count / $threshold) * 100, 1);
            $status = $pct >= 100 ? 'CRITICAL' : ($pct >= 70 ? 'WARNING' : 'OK');
            $recommendation = match ($status) {
                'CRITICAL' => "Particionar YA. Revisar change archivado 'partition-{$table}' o crear uno nuevo.",
                'WARNING' => "Monitorear semanalmente. Planificar partición para los próximos 30 días.",
                'OK' => 'Sin acción.',
            };
            $rows[] = [$table, number_format($count), number_format($threshold), "{$pct}%", $status, $recommendation];
        }

        $this->table(
            ['Tabla', 'Filas actuales', 'Threshold', '%', 'Estado', 'Recomendación'],
            $rows,
        );

        $hasCritical = false;
        foreach ($rows as $r) {
            if ($r[4] === 'CRITICAL') {
                $hasCritical = true;
                break;
            }
        }
        return $hasCritical ? self::FAILURE : self::SUCCESS;
    }
}
