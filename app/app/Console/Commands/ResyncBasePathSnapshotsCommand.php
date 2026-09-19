<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Task 4.3):
 *
 * Re-sincroniza `files.base_path_snapshot` con `storage_providers.base_path`
 * para todas las filas donde difieren. El observer FileObserver normalmente
 * mantiene esto en sync, pero este comando cubre batch repair tras:
 *
 *  - Migración inicial (filas pre-existentes sin snapshot).
 *  - Cambio administrativo en `storage_providers.base_path` (raro pero
 *    posible si se mueve un disco; el observer solo actúa en saves de
 *    File, no de Storage).
 *  - Drift por bugs previos al fix.
 *
 * Emite una fila en `file_mirror_audit_log` por fila modificada con
 * action='resync_snapshot', así queda trazabilidad de cuándo cambió el
 * snapshot y por qué ruta administrativa.
 */
class ResyncBasePathSnapshotsCommand extends Command
{
    protected $signature = 'files:resync-base-path-snapshots
                            {--dry-run : Solo reporta}
                            {--storage=* : Limita a uno o más storage IDs}
                            {--batch=2000 : Tamaño del lote}';

    protected $description = 'Re-sincroniza files.base_path_snapshot con storage_providers.base_path';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $storageFilter = (array) $this->option('storage');
        $batch = max(1, (int) $this->option('batch'));

        $storageClause = '';
        if (!empty($storageFilter)) {
            $ids = implode(',', array_map('intval', $storageFilter));
            $storageClause = "AND f.storage_provider_id IN ($ids)";
        }

        $countQuery = <<<SQL
SELECT COUNT(*) AS n
FROM files f
LEFT JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE (
    (f.base_path_snapshot IS NULL AND sp.base_path IS NOT NULL)
    OR (f.base_path_snapshot IS NOT NULL AND sp.base_path IS NULL)
    OR (f.base_path_snapshot IS DISTINCT FROM sp.base_path)
)
$storageClause
SQL;

        $drifted = (int) DB::selectOne($countQuery)->n;

        $this->info(sprintf('Detectadas %d filas con drift en base_path_snapshot.', $drifted));

        // Desglose por storage: permite acotar el backfill con --storage=<id>
        // cuando el total es grande (change `files-mirror-elimination`).
        $byStorage = DB::select(<<<SQL
SELECT f.storage_provider_id, COALESCE(sp.name, '(sin storage)') AS storage_name, COUNT(*) AS n
FROM files f
LEFT JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE (
    (f.base_path_snapshot IS NULL AND sp.base_path IS NOT NULL)
    OR (f.base_path_snapshot IS NOT NULL AND sp.base_path IS NULL)
    OR (f.base_path_snapshot IS DISTINCT FROM sp.base_path)
)
$storageClause
GROUP BY f.storage_provider_id, sp.name
ORDER BY n DESC
LIMIT 20
SQL);

        if (!empty($byStorage)) {
            $this->table(
                ['storage_provider_id', 'nombre', 'filas con drift'],
                array_map(fn ($r) => [$r->storage_provider_id, $r->storage_name, $r->n], $byStorage)
            );
        }

        if ($dryRun) {
            $this->info('DRY-RUN: use sin --dry-run para aplicar.');
            return self::SUCCESS;
        }

        if ($drifted === 0) {
            $this->info('Nada que sincronizar.');
            return self::SUCCESS;
        }

        $updated = 0;
        $offset = 0;

        while (true) {
            $rows = DB::select(<<<SQL
SELECT f.id AS file_id, f.storage_provider_id, sp.base_path AS new_snapshot
FROM files f
JOIN storage_providers sp ON sp.id = f.storage_provider_id
WHERE f.base_path_snapshot IS DISTINCT FROM sp.base_path
  $storageClause
ORDER BY f.id
LIMIT $batch OFFSET $offset
SQL);

            if (empty($rows)) {
                break;
            }

            $auditRows = [];
            foreach ($rows as $row) {
                DB::table('files')
                    ->where('id', $row->file_id)
                    ->update(['base_path_snapshot' => $row->new_snapshot]);

                $auditRows[] = [
                    'actor_user_id'    => null,
                    'action'           => 'resync_snapshot',
                    'mirror_file_id'   => null,
                    'canonical_file_id' => null,
                    'share_id'         => null,
                    'before_value'     => null,
                    'after_value'      => (string) $row->file_id,
                    'metadata'         => json_encode([
                        'storage_provider_id' => $row->storage_provider_id,
                        'new_snapshot' => $row->new_snapshot,
                    ]),
                    'created_at'       => now(),
                ];
                $updated++;
            }

            if (!empty($auditRows)) {
                DB::table('file_mirror_audit_log')->insert($auditRows);
            }

            $offset += $batch;
            $this->info(sprintf('  ...%d/%d sincronizadas', $updated, $drifted));

            usleep(100_000);
        }

        $this->info(sprintf('Re-sync completado: %d filas actualizadas.', $updated));

        return self::SUCCESS;
    }
}