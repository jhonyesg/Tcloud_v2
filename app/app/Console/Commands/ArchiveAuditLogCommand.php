<?php

namespace App\Console\Commands;

use App\Services\Ia\AuditLogArchiver;
use App\Services\Ia\RetentionPolicy;
use Illuminate\Console\Command;

/**
 * avisos-scan-coverage-completion: comando para mover filas antiguas de
 * watermark_audit_log a watermark_audit_log_archive.
 *
 * Política de retención configurable vía SystemSetting('audit_log_retention_days').
 * Default 90 días (rango válido 30-3650).
 *
 * Uso:
 *   php artisan avisos:archive-audit-log --dry-run
 *   php artisan avisos:archive-audit-log                    # usa retention policy
 *   php artisan avisos:archive-audit-log --days=180          # override
 */
class ArchiveAuditLogCommand extends Command
{
    protected $signature = 'avisos:archive-audit-log
        {--days= : Días de antigüedad para archivar (default: retention policy)}
        {--dry-run : Solo contar filas candidatas sin moverlas}';

    protected $description = 'Mueve filas antiguas de watermark_audit_log a watermark_audit_log_archive';

    public function handle(): int
    {
        $days = $this->option('days')
            ? (int) $this->option('days')
            : RetentionPolicy::getDays();

        $dryRun = (bool) $this->option('dry-run');

        $archiver = app(AuditLogArchiver::class);
        $count = $archiver->countOlderThan($days);

        $this->line("Filas con created_at < " . now()->subDays($days)->toDateTimeString() . ": {$count}");
        $this->line("(Política retención actual: {$days} días)");

        if ($dryRun) {
            $this->info('Dry-run: nada modificado.');
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Sin filas para archivar.');
            return self::SUCCESS;
        }

        $result = $archiver->archive($days);

        $this->info(sprintf(
            'Archivado: %d filas en %d chunk(s). Rango: %s → %s',
            $result['archived'],
            $result['chunks'],
            $result['min_created_at'] ?? '?',
            $result['max_created_at'] ?? '?',
        ));

        return self::SUCCESS;
    }
}

