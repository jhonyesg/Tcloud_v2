<?php

namespace App\Console\Commands;

use App\Services\Ia\AlertDeliveryService;
use Illuminate\Console\Command;

/**
 * mis-avisos-menciones Fase 1: scheduler de entrega de avisos.
 *
 * Cada minuto agrupa los pendientes vencidos por usuario y encola un digest
 * respetando cadencia, techo diario (emails_quota) y rate limiter global.
 * El cron del sistema ya corre schedule:run cada minuto.
 */
class DeliverAlertsCommand extends Command
{
    protected $signature = 'avisos:deliver-alerts {--dry-run : Solo contar pendientes vencidos}';

    protected $description = 'Entrega los avisos de menciones vencidos según la cadencia de cada cliente';

    public function handle(AlertDeliveryService $service): int
    {
        if ($this->option('dry-run')) {
            $pending = \Illuminate\Support\Facades\DB::table('alert_deliveries')
                ->whereNull('delivered_at')
                ->where('due_at', '<=', now())
                ->count();
            $this->info("Pendientes vencidos: {$pending}");

            return self::SUCCESS;
        }

        $queued = $service->run();

        if ($queued > 0) {
            $this->info("Digests encolados: {$queued}");
        }

        return self::SUCCESS;
    }
}