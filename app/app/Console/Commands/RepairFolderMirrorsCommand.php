<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\FilePhysicalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Task 4.1):
 *
 * Detecta pares folder espejo (mismo physical path en parent + sub-storage)
 * y setea `files.canonical_folder_id` del parent view para apuntar al canónico.
 *
 * Caso origen: 27.922 pares detectados en el spike dry-run del 2026-09-17.
 * Los parent views son aliases estructurales del sync (StorageSyncService
 * crea filas en cada storage durante el escaneo); los canónicos son los
 * que tienen los archivos físicos referenciados por las FKs aguas abajo.
 *
 * Detección:
 *   - Folders con `is_folder = true`, no trashados, no borrados.
 *   - Mismo `physical_path_normalized` (= lower(rtrim(base_path, '/') || '/' || ltrim(path, '/'))).
 *   - Relación estricta: el sub-storage (base_path más largo) es el canónico;
 *     el parent (base_path más corto) es el mirror.
 *
 * Flags:
 *   --dry-run           (default) imprime pares y sale sin mutar.
 *   --apply             ejecuta el el. Emite1 audit row por link.
 *   --storage=ID        limita la detección a1 storage (debug).
 *   --batch=N           tamaño del lote (default 1000).
 *
 * Idempotencia: `canonical_folder_id IS NULL` es el filtro; re-correr no
 * re-muta rows ya enlazados. Verificado en harness §5.1 scenario (d).
 */
class RepairFolderMirrorsCommand extends Command
{
    protected $signature = 'files:repair-folder-mirrors
                            {--dry-run : Por defecto, solo reporta}
                            {--apply : Aplica el backfill}
                            {--storage=* : Limita a uno o más storage IDs}
                            {--batch=1000 : Tamaño del lote}';

    protected $description = 'Detecta folder mirrors (parent/sub-storage) y setea canonical_folder_id en el parent view';

    public function handle(FilePhysicalIdentity $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $storageFilter = (array) $this->option('storage');
        $batch = max(1, (int) $this->option('batch'));

        if (!$apply && !$this->option('dry-run')) {
            $this->warn('Sin flag --apply ni --dry-run; asumiendo --dry-run.');
        }

        $pairs = $this->detectPairs($storageFilter);

        $this->info(sprintf('Detectados %d pares folder espejo (parent ↔ sub-storage).', count($pairs)));

        if (!$apply) {
            $this->table(
                ['parent_id', 'parent_storage', 'parent_path', 'canonical_id', 'canonical_storage', 'canonical_path', 'parent_children', 'canonical_children'],
                collect($pairs)->take(20)->map(fn($p) => [
                    $p->parent_id,
                    $p->parent_storage_name,
                    substr($p->parent_path, 0, 40),
                    $p->canonical_id,
                    $p->canonical_storage_name,
                    substr($p->canonical_path, 0, 40),
                    $p->parent_children,
                    $p->canonical_children,
                ])->all()
            );
            if (count($pairs) > 20) {
                $this->line(sprintf('... y %d pares más.', count($pairs) - 20));
            }
            $this->info('DRY-RUN: nada fue modificado. Use --apply para ejecutar.');
            return self::SUCCESS;
        }

        $linked = 0;
        $skipped = 0;
        $batches = array_chunk($pairs, $batch);

        foreach ($batches as $i => $batchPairs) {
            $this->info(sprintf('Procesando lote %d/%d (%d pares)...', $i + 1, count($batches), count($batchPairs)));

            foreach ($batchPairs as $pair) {
                $mirror = File::find($pair->parent_id);
                $canonical = File::find($pair->canonical_id);

                if (!$mirror || !$canonical) {
                    $skipped++;
                    continue;
                }

                if ($mirror->canonical_folder_id === $canonical->id) {
                    $skipped++;
                    continue;
                }

                $did = $resolver->link(
                    $mirror,
                    $canonical,
                    'repair_folder_mirrors_backfill',
                    null
                );

                if ($did) {
                    $linked++;
                } else {
                    $skipped++;
                }
            }

            if ($i < count($batches) - 1) {
                usleep(100_000); // 100ms entre lotes, evita saturar la BD
            }
        }

        $this->info("============================================================");
        $this->info(sprintf('Backfill completado: %d links creados, %d saltados.', $linked, $skipped));
        $this->info(sprintf('Audit rows esperadas: SELECT COUNT(*) FROM file_mirror_audit_log WHERE action=\'link_mirror\';'));

        return self::SUCCESS;
    }

    /**
     * Detecta pares folder espejo con la misma query probada en el spike.
     * Retorna una colección de objetos con parent_id, canonical_id, y metadatos.
     */
    private function detectPairs(array $storageFilter): array
    {
        $storageClause = '';
        if (!empty($storageFilter)) {
            $ids = implode(',', array_map('intval', $storageFilter));
            $storageClause = "AND (parent_row.storage_id IN ($ids) OR child_row.storage_id IN ($ids))";
        }

        $sql = <<<SQL
WITH norm AS (
  SELECT f.id, f.is_folder, f.storage_provider_id AS storage_id,
         f.parent_id, f.path, f.canonical_folder_id,
         lower(rtrim(sp.base_path,'/')) AS sp_base,
         lower(rtrim(sp.base_path,'/') || '/' || ltrim(f.path,'/')) AS abs_path,
         sp.name AS storage_name
  FROM files f
  JOIN storage_providers sp ON sp.id = f.storage_provider_id
  WHERE f.deleted_at IS NULL AND f.is_folder = true
)
SELECT
  parent_row.id AS parent_id,
  parent_row.storage_id AS parent_storage_id,
  parent_row.storage_name AS parent_storage_name,
  parent_row.path AS parent_path,
  child_row.id AS canonical_id,
  child_row.storage_id AS canonical_storage_id,
  child_row.storage_name AS canonical_storage_name,
  child_row.path AS canonical_path,
  (SELECT COUNT(*) FROM files c WHERE c.parent_id = parent_row.id AND c.deleted_at IS NULL) AS parent_children,
  (SELECT COUNT(*) FROM files c WHERE c.parent_id = child_row.id AND c.deleted_at IS NULL) AS canonical_children
FROM norm parent_row
JOIN norm child_row
  ON parent_row.abs_path = child_row.abs_path
 AND parent_row.id != child_row.id
 AND length(child_row.sp_base) > length(parent_row.sp_base)
 AND child_row.sp_base LIKE parent_row.sp_base || '%'
WHERE parent_row.canonical_folder_id IS NULL
  AND child_row.canonical_folder_id IS NULL
  $storageClause
SQL;

        return DB::select($sql);
    }
}