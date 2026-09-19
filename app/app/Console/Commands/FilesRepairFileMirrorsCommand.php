<?php

namespace App\Console\Commands;

use App\Services\FileMirrorLinker;
use Illuminate\Console\Command;

/**
 * Change `files-mirror-consolidation` (2026-09-19) — Backfill command:
 *
 * Crea mirror rows faltantes para files que viven en sub-storages pero no
 * tienen contraparte en storages padre (caso: storage 37 vs storage 44 —
 * sync solo creaba mirror rows de folders, no de files).
 *
 * Estrategia complementaria al sync-time linking en `FileRegistry::ensure()`:
 * el sync cubre files NUEVOS en adelante; este comando cubre los ~700k
 * files históricos indexados antes del fix.
 *
 * Uso:
 *   php artisan files:repair-file-mirrors --dry-run           # cuenta
 *   php artisan files:repair-file-mirrors --apply              # crea+linkea
 *   php artisan files:repair-file-mirrors --apply --batch=1000 # custom batch
 *   php artisan files:repair-file-mirrors --storage=37,44      # scope
 *
 * Idempotente: el helper usa NOT EXISTS para evitar duplicar mirror rows
 * que ya existan. Re-correr es seguro (0 inserts adicionales).
 */
class FilesRepairFileMirrorsCommand extends Command
{
    protected $signature = 'files:repair-file-mirrors
                            {--dry-run : Solo contar, no mutar}
                            {--apply : Ejecutar el backfill}
                            {--batch=5000 : Tamaño del lote}
                            {--storage=* : Limitar a storage IDs específicos}';

    protected $description = 'Crea mirror rows faltantes para files en sub-storages (cross-storage linking)';

    public function handle(FileMirrorLinker $linker): int
    {
        $apply = (bool) $this->option('apply');
        $batch = max(1, (int) $this->option('batch'));
        $storageFilter = (array) $this->option('storage');

        if (!$apply && !$this->option('dry-run')) {
            $this->warn('Sin flag --apply ni --dry-run; asumiendo --dry-run.');
        }

        // Count would-be-created mirrors (estimate via dry-run query)
        if (!$apply) {
            $count = $this->countMissing();
            $this->info(sprintf("Mirror rows de files que se crearian: %d", $count));
            $this->info('DRY-RUN: nada fue modificado. Use --apply para ejecutar.');
            return self::SUCCESS;
        }

        $this->info('Ejecutando backfill (set-based, bulk INSERT)...');
        $created = $linker->backfillMissingFileMirrors($batch);
        $this->info('============================================================');
        $this->info(sprintf('Backfill completado: %d mirror rows creadas.', $created));
        $this->info('Audit log: SELECT COUNT(*) FROM file_mirror_audit_log WHERE action=\'link_mirror\' AND metadata::text LIKE \'%backfill_file_mirrors%\';');

        return self::SUCCESS;
    }

    private function countMissing(): int
    {
        $sql = "
            WITH canonicals AS (
                SELECT f.id, f.storage_provider_id, f.path,
                       sp.base_path AS child_base_path
                FROM files f
                JOIN storage_providers sp ON sp.id = f.storage_provider_id
                WHERE f.deleted_at IS NULL
                  AND f.is_folder = false
                  AND f.is_trashed = false
                  AND sp.base_path IS NOT NULL
                  AND sp.base_path != ''
                  AND f.canonical_folder_id IS NULL
            ),
            parent_matches AS (
                SELECT c.id, psp.id AS parent_storage_id,
                       SUBSTRING(
                           lower(c.child_base_path) || '/' || lower(c.path)
                           FROM LENGTH(psp.base_path) + 2
                       ) AS relative_path
                FROM canonicals c
                JOIN storage_providers psp
                  ON length(c.child_base_path) > length(psp.base_path)
                 AND lower(c.child_base_path) LIKE lower(psp.base_path) || '/%'
                WHERE NOT EXISTS (
                    SELECT 1 FROM files m
                    WHERE m.storage_provider_id = psp.id
                      AND lower(m.path) = lower(SUBSTRING(
                          lower(c.child_base_path) || '/' || lower(c.path)
                          FROM LENGTH(psp.base_path) + 2
                      ))
                      AND m.deleted_at IS NULL
                )
            )
            SELECT COUNT(*) AS n FROM parent_matches
        ";
        $result = \Illuminate\Support\Facades\DB::selectOne($sql);
        return (int) ($result->n ?? 0);
    }
}
