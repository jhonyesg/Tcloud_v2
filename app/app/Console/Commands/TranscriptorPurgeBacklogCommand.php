<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procedimiento one-shot de cutover para la migracion a cola nativa PG
 * (transcriptor-pg-native-queue).
 *
 * Pasos:
 *  1. Lee el setting legacy `transcriptor.target_redis_queue` y lo migra al
 *     nuevo `transcriptor.target_pg_queue` (mismo valor).
 *  2. DELETE FROM system_settings WHERE key='transcriptor.target_redis_queue'.
 *  3. Si --execute: DELETE FROM transcriptions WHERE state IN ('pending','queued').
 *     Si NO --execute: dry-run con conteos.
 *
 * Por que DELETE y no pasar a state='dead': el operador decidio en la
 * exploracion (2026-09-15) que los pendientes pre-migracion no tienen valor y
 * "pasarlo a state='dead' no soluciona, mejor eliminarlo y se escanea y se
 * lista mejor".
 *
 * Pre-requisito: backup logico previo (`--backup-path=/var/backups/transcriptions_pre_cutover.csv`).
 */
class TranscriptorPurgeBacklogCommand extends Command
{
    protected $signature = 'transcriptor:purge-backlog
                            {--backup-path= : Path al CSV de backup (referencia informativa; no se valida)}
                            {--execute : Aplicar el DELETE. Sin este flag es dry-run}';

    protected $description = 'Cutover: migra setting target_redis_queue → target_pg_queue y purga pendientes pre-migracion.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $backupPath = (string) $this->option('backup-path');

        if ($execute && $backupPath === '') {
            $this->error('Con --execute se requiere --backup-path para registrar la referencia al backup logico previo.');
            return Command::FAILURE;
        }

        $legacyKey = 'transcriptor.target_redis_queue';
        $newKey = 'transcriptor.target_pg_queue';

        $legacyValue = SystemSetting::get($legacyKey);

        $pendingCount = (int) DB::table('transcriptions')
            ->whereIn('state', [Transcription::STATE_PENDING, Transcription::STATE_QUEUED])
            ->count();

        $this->line("Pre-cutover snapshot:");
        $this->line("  legacy setting: {$legacyKey}=" . ($legacyValue ?? '(no definido, default 140)'));
        $this->line("  nuevo setting:  {$newKey}=" . SystemSetting::get($newKey, '(a crear)'));
        $this->line("  filas a purgar (pending/queued): {$pendingCount}");
        $this->line("  backup path:    " . ($backupPath ?: '(no especificado)'));

        if (!$execute) {
            $this->newLine();
            $this->warn('[DRY-RUN] Sin --execute no se aplica nada. Re-ejecuta con --execute --backup-path=<csv> para confirmar.');
            return Command::SUCCESS;
        }

        // 1. UPSERT nuevo setting con el valor legacy (o default 140).
        $valueToSet = $legacyValue !== null ? (string) $legacyValue : '140';
        SystemSetting::set($newKey, $valueToSet);

        // 2. DELETE setting legacy.
        SystemSetting::where('key', $legacyKey)->delete();

        // 3. DELETE backlog.
        $deletedRows = 0;
        do {
            $affected = DB::table('transcriptions')
                ->whereIn('state', [Transcription::STATE_PENDING, Transcription::STATE_QUEUED])
                ->limit(1000)
                ->delete();
            $deletedRows += $affected;
        } while ($affected > 0);

        Log::warning('transcriptor.cutover.applied', [
            'legacy_setting' => $legacyKey,
            'new_setting' => $newKey,
            'migrated_value' => $valueToSet,
            'rows_deleted' => $deletedRows,
            'backup_path' => $backupPath,
        ]);

        $this->info("Cutover aplicado:");
        $this->line("  {$legacyKey} -> {$newKey}={$valueToSet}");
        $this->line("  filas purgadas: {$deletedRows}");

        return Command::SUCCESS;
    }
}