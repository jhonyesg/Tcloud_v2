<?php

namespace App\Console\Commands;

use App\Services\Ia\AvisosScanService;
use Illuminate\Console\Command;

/**
 * avisos-scan-configuration: escaneo de menciones (cron y manual).
 *
 * Tick fijo cada 5 min (routes/console.php, withoutOverlapping 15): el
 * comando decide internamente si toca correr — igual patrón que
 * sessions_cleanup_interval_minutes, porque Laravel cachea la expresión
 * cron al boot y la frecuencia real vive en SystemSetting.
 *
 * Separación estricta scan→entrega: este comando JAMÁS envía correos;
 * la entrega es de avisos:deliver-alerts.
 */
class ScanMentionsCommand extends Command
{
    protected $signature = 'avisos:scan
        {--storage= : Filtrar por storage_provider_id}
        {--transcription= : Escanear una transcripción puntual}
        {--from= : Fecha de terminación desde (Y-m-d)}
        {--to= : Fecha de terminación hasta (Y-m-d)}
        {--limit= : Lote máximo por corrida (default 50)}
        {--force : Re-escanear borrando los hits previos de los objetivos}
        {--no-window : Catch-up: sin límite de fecha (solo manual; ignora la ventana configurada)}
        {--dry-run : Solo contar candidatos sin escanear}
        {--origin=cron : Origen registrado (cron|manual)}
        {--ignore-schedule : Correr aunque el tick automático no toque (para manual)}';

    protected $description = 'Escanea transcripciones terminadas sin hits y genera menciones (avisos inteligentes)';

    public function handle(AvisosScanService $service): int
    {
        $opts = [
            'origin' => $this->option('origin') === 'manual' ? 'manual' : 'cron',
            'storageId' => $this->option('storage') ?: null,
            'transcriptionId' => $this->option('transcription') ?: null,
            'from' => $this->option('from') ?: null,
            'to' => $this->option('to') ?: null,
            'limit' => $this->option('limit') ? (int) $this->option('limit') : null,
            'force' => (bool) $this->option('force'),
            'noWindow' => (bool) $this->option('no-window'),
            'dryRun' => (bool) $this->option('dry-run'),
        ];

        // Decisión de tick SOLO para la corrida automática: el cron consulta
        // settings y la última corrida exitosa; el manual ignora la ventana.
        if ($opts['origin'] === 'cron' && !$this->option('ignore-schedule')) {
            $settings = $service->settings();
            if (!$settings['enabled']) {
                $this->line('Escaneo automático desactivado — nada que hacer.');

                return self::SUCCESS;
            }
            if (!$service->shouldRunCron()) {
                $this->line('Fuera de ventana de intervalo (' . $settings['intervalMinutes'] . ' min) — nada que hacer.');

                return self::SUCCESS;
            }
        }

        if ($opts['dryRun']) {
            $result = $service->run($opts);
            $this->info("Candidatos en ventana: {$result['candidates']}");
            foreach (($result['sample'] ?? []) as $s) {
                $this->line("  - transcripción {$s['id']} (terminó {$s['finished_at']})");
            }

            return self::SUCCESS;
        }

        $result = $service->run($opts);
        $this->info("Corrida {$result['status']} — escaneadas: {$result['scanned']}, hits nuevos: {$result['hitsNew']}, fallos: {$result['failed']}, {$result['durationMs']} ms");

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}