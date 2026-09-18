<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\SystemSetting;

/**
 * Auditoria read-only de storages duplicados (change
 * `storage-physical-path-normalization`, 2026-09-17).
 *
 * Detecta pares de storages que comparten el mismo `physical_path_normalized`
 * y reporta conteos exactos de archivos, FKs y watermarks para cada uno. NO
 * muta nada: este comando es seguro para correr via cron (diario) sin
 * supervision.
 *
 * Stage 3 del change (`enforce_unique_physical_path` migration) aborta si
 * `SystemSetting('storage.duplicates_remaining') > 0`. Este comando actualiza
 * ese setting con el conteo actual, asi el dashboard y la migration final
 * saben cuantos pares quedan.
 *
 * Uso:
 *   php artisan storages:detect-duplicate-paths            # log + stdout + setting
 *   php artisan storages:detect-duplicate-paths --dry-run  # solo stdout
 */
class DetectDuplicatePathsCommand extends Command
{
    protected $signature = 'storages:detect-duplicate-paths
                            {--dry-run : Solo imprime; NO actualiza SystemSetting}
                            {--include-delegation-leaks : Tambien reporta files con storage_provider_id incorrecto (delegation leak)}';

    protected $description = 'Detecta storages con base_path duplicado (auditoria read-only).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Detectando storages duplicados...');

        // Query 1: pares (kind, physical_path_normalized) con count > 1
        $duplicates = DB::select("
            SELECT kind, physical_path_normalized, array_agg(id ORDER BY id) AS storage_ids
            FROM storage_providers
            WHERE duplicate_of_storage_id IS NULL
              AND physical_path_normalized IS NOT NULL
            GROUP BY kind, physical_path_normalized
            HAVING count(*) > 1
            ORDER BY kind, physical_path_normalized
        ");

        if (empty($duplicates)) {
            $this->info('No se encontraron storages duplicados. BD en estado normalizado.');
            if (!$dryRun) {
                SystemSetting::set('storage.duplicates_remaining', '0');
            }
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($duplicates as $dup) {
            $ids = $this->pgArrayToPhp($dup->storage_ids);
            $canonical = StorageProvider::canonicalFor($dup->physical_path_normalized, $dup->kind);
            $canonicalId = $canonical?->id ?? $ids[0];

            foreach ($ids as $sid) {
                $s = StorageProvider::find($sid);
                if ($s === null) continue;

                $fileCount = DB::table('files')->where('storage_provider_id', $sid)->count();
                $txCount = (int) DB::table('files as f')
                    ->join('transcriptions as t', 't.file_id', '=', 'f.id')
                    ->where('f.storage_provider_id', $sid)
                    ->count();
                $shareCount = (int) DB::table('files as f')
                    ->join('shares as s', 's.file_id', '=', 'f.id')
                    ->where('f.storage_provider_id', $sid)
                    ->count();
                $watermarkCount = DB::table('keyword_scan_watermarks')->where('storage_provider_id', $sid)->count();

                $rows[] = [
                    'path' => $dup->physical_path_normalized,
                    'id' => $sid,
                    'name' => $s->name,
                    'is_canonical' => $sid === $canonicalId ? 'YES' : '',
                    'files' => $fileCount,
                    'tx' => $txCount,
                    'shares' => $shareCount,
                    'watermarks' => $watermarkCount,
                ];
            }
        }

        $this->table(
            ['Path', 'ID', 'Name', 'Canonical', 'Files', 'Tx', 'Shares', 'Watermarks'],
            $rows
        );

        $pairCount = count($duplicates);
        $this->info(sprintf(
            'Total: %d pares duplicados, %d storages afectados.',
            $pairCount,
            count($rows)
        ));

        if (!$dryRun) {
            SystemSetting::set('storage.duplicates_remaining', (string) $pairCount);
            Log::info('storages.duplicates_detected', [
                'pair_count' => $pairCount,
                'storage_count' => count($rows),
            ]);
            $this->line('');
            $this->info(sprintf(
                'SystemSetting(storage.duplicates_remaining) = %d. La migration de UNIQUE constraint se aplicara cuando llegue a 0.',
                $pairCount
            ));
        } else {
            $this->line('--dry-run: SystemSetting NO actualizado');
        }

        // Cambio `2026-09-17-self-healing-sync-permissions`: si --include-delegation-leaks
        // esta presente, agregar una seccion adicional con files cuyo path cae
        // bajo un sub-storage mas especifico (causa del bug "Forbidden" en descargas).
        if ($this->option('include-delegation-leaks')) {
            $this->line('');
            $this->line('=== DELEGATION LEAKS (files en parent con path bajo sub) ===');
            $this->line('');

            $leaks = DB::select("
                SELECT s1.id AS origin_id, s1.name AS origin_name,
                       s2.id AS target_id, s2.name AS target_name,
                       count(*) AS leaked_files
                FROM files f
                JOIN storage_providers s1 ON s1.id = f.storage_provider_id
                JOIN storage_providers s2 ON s2.duplicate_of_storage_id IS NULL
                  AND s2.base_path IS NOT NULL AND s2.base_path <> ''
                  AND s2.id != f.storage_provider_id
                  AND s2.base_path != s1.base_path
                  AND (s1.base_path || '/' || f.path || '/') LIKE s2.base_path || '/%'
                WHERE NOT f.is_folder AND NOT f.is_trashed
                GROUP BY s1.id, s1.name, s2.id, s2.name
                HAVING count(*) > 0
                ORDER BY leaked_files DESC
                LIMIT 50
            ");

            if (empty($leaks)) {
                $this->info('No se encontraron delegation leaks. El self-healing sync mantiene los storage_provider_id correctos.');
            } else {
                $leakRows = [];
                $totalLeaked = 0;
                foreach ($leaks as $l) {
                    $leakRows[] = [
                        '#' . $l->origin_id . ' ' . $l->origin_name,
                        '#' . $l->target_id . ' ' . $l->target_name,
                        $l->leaked_files,
                    ];
                    $totalLeaked += $l->leaked_files;
                }
                $this->table(['Origin (parent)', 'Target (sub deberia ser)', 'Files leaked'], $leakRows);
                $this->line('');
                $this->info(sprintf(
                    'Total: %d delegation leaks (files en parent con path bajo sub). Repair: php artisan files:repair-delegation-leak --apply --to-storage=<ID>',
                    $totalLeaked
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Convierte un array PG (formato "{1,2,3}") a PHP array. PostgreSQL
     * devuelve arrays como strings en PHP PDO; helper necesario para
     * `array_agg` en la query.
     */
    private function pgArrayToPhp(string $pgArray): array
    {
        $pgArray = trim($pgArray, '{}');
        if ($pgArray === '') return [];
        return array_map('intval', explode(',', $pgArray));
    }
}
