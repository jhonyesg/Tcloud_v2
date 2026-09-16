<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ia\CorrectionTriageService;
use Illuminate\Console\Command;

/**
 * Triage en capas de correcciones pending de /ia/correcciones.
 *
 * Cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage.
 *
 * Aplica 6 capas de descarte para reducir una cola de pendientes inflada
 * por el extractor `ai-coherence-learn` bug a un set chico y audit-able.
 *
 * Uso:
 *   php artisan corrections:triage-pending --dry-run                     # solo reporte
 *   php artisan corrections:triage-pending --apply                       # descartar ruido, dejar supervivientes para revisión humana
 *   php artisan corrections:triage-pending --apply --auto-approve-keep   # además auto-aprueba las KEEP (variantes ortográficas) con undo 5 min
 *   php artisan corrections:triage-pending --apply --auto-approve-keep --days=14 --max=5000
 *   php artisan corrections:triage-pending --dry-run --days=30           # acotar por fecha
 */
class CorrectionsTriagePendingCommand extends Command
{
    protected $signature = 'corrections:triage-pending
                            {--dry-run : Solo reporta, no modifica la BD}
                            {--apply : Aplicar las 5 capas de descarte (sin auto-aprobar KEEP)}
                            {--auto-approve-keep : Además auto-aprobar las variantes ortográficas KEEP (requiere --apply o implícito)}
                            {--max=10000 : Tope de candidatas por corrida (default 10k)}
                            {--days= : Filtrar a pending creadas en últimos N días (omitir = todas)}';

    protected $description = 'Triage en capas de correcciones pending para reducir cola y dejar un set chico y revisable.';

    public function handle(CorrectionTriageService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        $autoApproveKeep = (bool) $this->option('auto-approve-keep');
        $max = max(1, (int) $this->option('max'));
        $daysOpt = $this->option('days');
        $daysBack = null;
        if ($daysOpt !== null && $daysOpt !== '') {
            $daysBack = (int) $daysOpt;
            if ($daysBack <= 0) {
                $this->error('--days debe ser entero positivo (omitir = todas).');
                return self::FAILURE;
            }
        }

        // Si no hay --apply ni --dry-run, default a dry-run por seguridad.
        if (!$apply && !$dryRun) {
            $this->warn('Sin --apply ni --dry-run, asumiendo --dry-run (seguro).');
            $dryRun = true;
        }

        // Si --auto-approve-keep se da sin --apply, aplicar también.
        if ($autoApproveKeep && !$apply && !$dryRun) {
            $apply = true;
        }

        $this->line(sprintf(
            'Triage: dry_run=%s apply=%s auto_approve_keep=%s max=%d days=%s',
            $dryRun ? 'yes' : 'no',
            $apply ? 'yes' : 'no',
            $autoApproveKeep ? 'yes' : 'no',
            $max,
            $daysBack ?? 'all'
        ));

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        if (!$admin) {
            $this->error('No hay usuario admin para asociar el bulk_action_id.');
            return self::FAILURE;
        }

        try {
            $result = $service->run(
                dryRun: $dryRun,
                autoApproveKeep: $autoApproveKeep && !$dryRun,
                max: $max,
                daysBack: $daysBack,
                by: $admin,
            );
        } catch (\Throwable $e) {
            $this->error('Triage falló: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('=== Reporte del triage ===');
        $this->line('run_id: ' . ($result['run_id'] ?? '?'));
        $this->line('modo: ' . ($result['mode'] ?? '?'));
        $this->line('supervivientes para revisión: ' . ($result['survivors_for_review'] ?? 0));
        $this->line('auto-aprobadas (KEEP): ' . ($result['auto_approve_candidates'] ?? 0));
        if (!empty($result['bulk_action_id'])) {
            $this->line('bulk_action_id: ' . $result['bulk_action_id']);
            $this->line('undo_expires_at: ' . ($result['undo_expires_at'] ?? ''));
        }

        $this->newLine();
        $this->info('Capas:');
        foreach (($result['layers'] ?? []) as $i => $layer) {
            $row = sprintf('  %d. %s', $i + 1, $layer['name'] ?? '?');
            $row .= sprintf(' — descartadas: %d', $layer['discarded'] ?? 0);
            if (isset($layer['survivors_keep'])) {
                $row .= sprintf(' / keep: %d, review: %d', $layer['survivors_keep'], $layer['survivors_review']);
            } elseif (isset($layer['survivors'])) {
                $row .= sprintf(' / supervivientes: %d', $layer['survivors']);
            } elseif (isset($layer['warmed'])) {
                $row .= sprintf(' / warmed: %d, errored: %d', $layer['warmed'], $layer['errored']);
            }
            $this->line($row);
            if (!empty($layer['reason'])) {
                $this->line('     ' . $layer['reason']);
            }
        }

        return self::SUCCESS;
    }
}
