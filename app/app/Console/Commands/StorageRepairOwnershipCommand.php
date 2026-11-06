<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Models\SystemSetting;
use App\Services\StorageOwnershipProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Repara paths reportados como `untraversable` por `storage:audit-ownership`.
 *
 * Estrategia:
 *   - SOLO paths con contenido en BD (cross-check via `storage_health:<id>:untraversable_paths`).
 *   - SOLO si el UID es 0 (root). Aborta con instrucción clara si no.
 *   - Dry-run por defecto; --apply requiere confirmación interactiva o --yes.
 *   - Snapshot pre-cambio a tabla `files_ownership_pre_repair_<ts>` con las filas
 *     afectadas, para rollback granular vía SQL.
 *   - Denylist dura (no override por flag): System Volume Information, $RECYCLE.BIN,
 *     .Recycle_bin, .Trash*, lost+found, paths que empiezan con ..
 *
 * Uso:
 *   sudo php artisan storage:repair-ownership --storage=55
 *   sudo php artisan storage:repair-ownership --storage=55 --apply --yes
 *   sudo php artisan storage:repair-ownership --storage=55 --apply --limit=3 --yes
 *   sudo php artisan storage:repair-ownership --drop-old-snapshots=30
 *
 * Referencia: storage-readability-audit-and-repair (OpenSpec).
 */
class StorageRepairOwnershipCommand extends Command
{
    protected $signature = 'storage:repair-ownership
                            {--storage= : StorageProvider ID a reparar (obligatorio salvo --drop-old-snapshots)}
                            {--limit=10 : Máximo de paths a procesar por corrida}
                            {--apply : Ejecutar chown/chmod (default: dry-run)}
                            {--yes : Salta confirmación interactiva cuando --apply)}
                            {--drop-old-snapshots=0 : Dropear tablas files_ownership_pre_repair_* con más de N días (0=no-op)}
                            {--admin-group=storage-admins : Grupo destino del chgrp}
                            {--fpm-user=www : Usuario PHP-FPM para el chown}';

    protected $description = 'Repara paths untraversable del base_path de un StorageProvider (requiere UID 0).';

    public function handle(): int
    {
        // Tarea 3.8: drop-old-snapshots independiente del resto.
        $dropDays = (int) $this->option('drop-old-snapshots');
        if ($dropDays > 0) {
            return $this->dropOldSnapshots($dropDays);
        }

        $storageId = $this->option('storage');
        if (!$storageId) {
            $this->error('Falta --storage=ID. Especifique el StorageProvider a reparar.');
            return self::FAILURE;
        }

        // Tarea 3.2: UID gate.
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->error('Repair requiere UID 0. Ejecute con sudo:');
            $this->line('  sudo php artisan storage:repair-ownership --storage=' . $storageId . ($this->option('apply') ? ' --apply' : '') . ' --yes');
            return self::FAILURE;
        }

        $storage = StorageProvider::find((int) $storageId);
        if (!$storage) {
            $this->error("StorageProvider {$storageId} no existe.");
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');
        $yes = (bool) $this->option('yes');
        $adminGroup = $this->option('admin-group');
        $fpmUser = $this->option('fpm-user');

        // Tarea 3.3: cargar paths desde SystemSetting escrito por el audit.
        $storedPathsJson = SystemSetting::get("storage_health:{$storage->id}:untraversable_paths", '[]');
        $storedPaths = json_decode($storedPathsJson, true) ?: [];

        if (empty($storedPaths)) {
            $this->warn(sprintf("No hay paths untraversable cacheados para storage %d. Corra primero:",
                $storage->id));
            $this->line("  php artisan storage:audit-ownership --storage={$storage->id} --scope=untraversable");
            return self::SUCCESS;
        }

        // Tarea 3.4: aplicar denylist.
        $pathList = array_column($storedPaths, 'path');
        $filter = StorageOwnershipProbe::applyDenylist($pathList);

        if (empty($filter['allowed'])) {
            $this->error('Todos los paths cayeron en denylist. Abort.');
            $this->line('  Denied: ' . implode(', ', $filter['denied']));
            return self::FAILURE;
        }

        if (!empty($filter['denied'])) {
            $this->warn(sprintf("Denylist excluyó %d paths: %s",
                count($filter['denied']),
                implode(', ', $filter['denied'])));
        }

        // Tarea 3.7: --limit (cap, no fail).
        $paths = array_slice($filter['allowed'], 0, $limit);

        $this->info(sprintf("Storage %d (%s): %d paths a procesar%s",
            $storage->id, $storage->name, count($paths),
            $apply ? ' [APPLY]' : ' [DRY-RUN]'));

        // Tarea 3.6: dry-run print.
        if (!$apply) {
            $this->line('');
            $this->line(sprintf("%-50s %-8s %-10s %-8s", 'PATH', 'MODE', 'OWNER:GROUP', 'BD_COUNT'));
            foreach ($paths as $p) {
                $stored = collect($storedPaths)->firstWhere('path', $p) ?? [];
                $stat = @stat($p);
                $mode = $stat ? sprintf('%04o', $stat['mode'] & 0777) : '????';
                $owner = $stat ? $stat['uid'] : '?';
                $group = $stat ? $stat['gid'] : '?';
                $bd = $stored['bd_count'] ?? 0;
                $this->line(sprintf("%-50s %-8s %d:%-8d %-8d",
                    substr($p, -50), $mode, $owner, $group, $bd));
            }
            $this->line('');
            $this->warn('DRY-RUN. Use --apply --yes para ejecutar chown+chmod.');
            return self::SUCCESS;
        }

        // Tarea 3.5: confirmación interactiva si --apply sin --yes y >5 paths.
        if (!$yes && count($paths) > 5) {
            $this->line('');
            $this->line(sprintf("Repair %d paths sobre storage %d. ¿Continuar? [y/N]", count($paths), $storage->id));
            $answer = trim((string) fgets(STDIN));
            if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
                $this->warn('Abort por respuesta no-afirmativa.');
                return self::SUCCESS;
            }
        }

        // Tarea 3.7: crear tabla + ejecutar chown/chmod.
        $snapshotTable = sprintf('files_owners_pre_repair_%s', now()->format('Ymd_His'));
        $totalSnapshotted = 0;

        try {
            DB::statement(sprintf(
                'CREATE TABLE %s (LIKE files INCLUDING ALL)',
                $snapshotTable
            ));

            // Snapshot pre-cambio: copiar filas que viven bajo cada path.
            foreach ($paths as $p) {
                $entry = $p;
                // path absoluto -> path relativo al base_path (lo que está en `files.path`).
                $basePath = $storage->base_path;
                $relPath = ltrim(substr($entry, strlen(rtrim($basePath, '/'))), '/');
                $relPrefix = rtrim($relPath, '/');

                $count = File::query()
                    ->where('storage_provider_id', $storage->id)
                    ->where(function ($q) use ($relPrefix) {
                        $q->where('path', $relPrefix)
                            ->orWhere('path', 'LIKE', $relPrefix . '/%');
                    })
                    ->count();

                if ($count > 0) {
                    DB::statement(
                        sprintf(
                            'INSERT INTO %s SELECT * FROM files WHERE storage_provider_id=%d AND (path=%s OR path LIKE %s)',
                            $snapshotTable,
                            $storage->id,
                            "'" . addslashes($relPrefix) . "'",
                            "'" . addslashes($relPrefix . '/%') . "'"
                        )
                    );
                    $totalSnapshotted += $count;
                }
            }

            Log::info('storage.repair_ownership.snapshot_created', [
                'storage_id' => $storage->id,
                'snapshot_table' => $snapshotTable,
                'rows_copied' => $totalSnapshotted,
            ]);
            $this->info(sprintf("  snapshot: %s (%d filas)", $snapshotTable, $totalSnapshotted));

            // chown -R + chmod -R por path.
            $repaired = 0;
            $errors = 0;
            foreach ($paths as $p) {
                $chownCmd = sprintf('chown -R %s:%s %s 2>&1',
                    escapeshellarg($fpmUser),
                    escapeshellarg($adminGroup),
                    escapeshellarg($p));
                $chmodCmd = sprintf('chmod -R u+rwX,g+rwX %s 2>&1',
                    escapeshellarg($p));

                $this->line(sprintf("  [apply] %s", $p));
                $r1 = Process::timeout(1800)->run($chownCmd);
                if (!$r1->successful()) {
                    $combined = $r1->errorOutput() . $r1->output();
                    $hint = '';
                    if (str_contains($combined, 'Permission denied')) {
                        $hint = ' (¿NFS con root_squash? Cliente root no puede leer directorios 700 ajenos; requiere ejecutar el chown en el NFS server, o `no_root_squash` en el export)';
                    }
                    $this->error("    chown falló: " . trim($combined) . $hint);
                    $errors++;
                    Log::error('storage.repair_ownership.chown_failed', [
                        'storage_id' => $storage->id,
                        'path' => $p,
                        'stderr' => $combined,
                        'is_nfs_root_squash' => str_contains($combined, 'Permission denied'),
                    ]);
                    continue;
                }

                $r2 = Process::timeout(1800)->run($chmodCmd);
                if (!$r2->successful()) {
                    $this->error("    chmod falló: " . $r2->errorOutput() . $r2->output());
                    $errors++;
                    Log::error('storage.repair_ownership.chmod_failed', [
                        'storage_id' => $storage->id,
                        'path' => $p,
                        'stderr' => $r2->errorOutput() . $r2->output(),
                    ]);
                    continue;
                }

                $repaired++;
            }

            // Resetear metricas persistidas si todo OK.
            if ($errors === 0) {
                SystemSetting::set("storage_health:{$storage->id}:untraversable_count", '0');
                SystemSetting::set("storage_health:{$storage->id}:untraversable_paths", '[]');
                SystemSetting::set("storage_health:audit_finished_at", now()->toIso8601String());
            }

            $this->newLine();
            $this->line(sprintf("=== RESUMEN ===  paths=%d  repaired=%d  errors=%d  snapshot=%s",
                count($paths), $repaired, $errors, $snapshotTable));

            Log::info('storage.repair_ownership.summary', [
                'storage_id' => $storage->id,
                'paths_count' => count($paths),
                'repaired' => $repaired,
                'errors' => $errors,
                'snapshot_table' => $snapshotTable,
                'snapshot_rows' => $totalSnapshotted,
            ]);

            return $errors > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            // Si algo falla a mitad, droppear la tabla snapshot para no dejar basura.
            try {
                DB::statement("DROP TABLE IF EXISTS {$snapshotTable}");
            } catch (\Throwable $dropEx) {
            }
            Log::error('storage.repair_ownership.exception', [
                'storage_id' => $storage->id,
                'error' => $e->getMessage(),
            ]);
            $this->error("Excepción durante repair: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function dropOldSnapshots(int $days): int
    {
        $cutoff = now()->subDays($days);
        $tables = DB::select("
            SELECT tablename FROM pg_tables
            WHERE schemaname = 'public'
              AND tablename LIKE 'files_owners_pre_repair_%'
        ");

        $dropped = 0;
        foreach ($tables as $t) {
            $tsStr = substr($t->tablename, strlen('files_owners_pre_repair_'));
            try {
                $ts = \Carbon\Carbon::createFromFormat('Ymd_His', $tsStr);
            } catch (\Throwable $e) {
                continue;
            }
            if ($ts->lt($cutoff)) {
                DB::statement("DROP TABLE IF EXISTS {$t->tablename}");
                $dropped++;
                $this->line("  dropped: {$t->tablename}");
            }
        }

        $this->info(sprintf("Dropped %d tablas snapshot older than %d dias.", $dropped, $days));
        return self::SUCCESS;
    }
}