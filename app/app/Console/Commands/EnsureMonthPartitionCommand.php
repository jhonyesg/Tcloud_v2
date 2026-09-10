<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * segment-keyword-hits-partitioning: scaffolding para crear particiones
 * mensuales de segment_keyword_hits. NO activa la partición (la tabla no es
 * aún PARTICIONADA; ver change archivado para detalles).
 *
 * Cuando se active la partición real, este comando:
 *   php artisan avisos:ensure-month-partition --month=2026-10
 *
 * es la herramienta para mantener las particiones al día (cron mensual).
 *
 * Hoy sólo verifica que el scaffolding esté listo y, si la tabla aún NO es
 * particionada, devuelve SKIPPED con instrucciones (NO falla).
 */
class EnsureMonthPartitionCommand extends Command
{
    protected $signature = 'avisos:ensure-month-partition
        {--month= : Mes objetivo (YYYY-MM). Default: mes actual}';

    protected $description = 'Crea la partición mensual de segment_keyword_hits (idempotente, scaffolding)';

    public function handle(): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error("Mes inválido: '{$month}'. Formato esperado YYYY-MM.");
            return self::FAILURE;
        }

        try {
            $result = DB::selectOne("SELECT segment_keyword_hits_create_partition(?) AS result", [$month]);
            $this->line("→ {$result->result}");
            return str_starts_with($result->result, 'CREATED') || str_starts_with($result->result, 'EXISTS')
                ? self::SUCCESS
                : self::SUCCESS; // SKIPPED también es éxito: scaffolding funcionando.
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
