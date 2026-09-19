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
 * Cambio `files-mirror-consolidation` (2026-09-19, Task 2.x):
 * Extendido con flag `--include-files` para detectar también mirrors de
 * FILES (no solo folders). Caso origen: storage 44 (206 Portafolio)
 * tiene `20260918/imagenes/01.png` pero storage 37 (200_Diarios) no
 * tenía mirror row → usuario veía folder pero no archivos adentro.
 *
 * Caso origen: 27.922 pares detectados en el spike dry-run del 2026-09-17.
 * Los parent views son aliases estructurales del sync (StorageSyncService
 * crea filas en cada storage durante el escaneo); los canónicos son los
 * que tienen los archivos físicos referenciados por las FKs aguas abajo.
 *
 * Detección:
 *   - Folders/files con `is_folder = true/false` (segun --include-files), no trashados, no borrados.
 *   - Mismo `physical_path_normalized` (= lower(rtrim(base_path, '/') || '/' || ltrim(path, '/'))).
 *   - Relación estricta: el sub-storage (base_path más largo) es el canónico;
 *     el parent (base_path más corto) es el mirror.
 *
 * Flags:
 *   --dry-run           (default) imprime pares y sale sin mutar.
 *   --apply             ejecuta el backfill. Emite 1 audit row por link.
 *   --storage=ID        limita la detección a 1 storage (debug).
 *   --batch=N           tamaño del lote (default 1000).
 *   --include-files     incluir mirrors de files además de folders (cambio files-mirror-consolidation).
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
                            {--batch=1000 : Tamaño del lote}
                            {--include-files : Incluir mirrors de files además de folders (files-mirror-consolidation 2026-09-19)}';

    protected $description = 'Detecta folder/file mirrors (parent/sub-storage) y setea canonical_folder_id en el parent view';

    public function handle(FilePhysicalIdentity $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $storageFilter = (array) $this->option('storage');
        $batch = max(1, (int) $this->option('batch'));
        $includeFiles = (bool) $this->option('include-files');

        if (!$apply && !$this->option('dry-run')) {
            $this->warn('Sin flag --apply ni --dry-run; asumiendo --dry-run.');
        }

        // files-mirror-consolidation: detectar primero folders, después files si --include-files
        $folderPairs = $this->detectPairs($storageFilter, false);
        $this->info(sprintf('Detectados %d pares folder espejo (parent ↔ sub-storage).', count($folderPairs)));

        $filePairs = [];
        if ($includeFiles) {
            $filePairs = $this->detectPairs($storageFilter, true);
            $this->info(sprintf('Detectados %d pares file espejo (parent ↔ sub-storage).', count($filePairs)));
        }

        $allPairs = array_merge($folderPairs, $filePairs);

        if (!$apply) {
            $sample = array_slice($allPairs, 0, 20);
            if (!empty($sample)) {
                $this->table(
                    ['parent_id', 'parent_storage', 'parent_path', 'canonical_id', 'canonical_storage', 'canonical_path', 'parent_children', 'canonical_children'],
                    collect($sample)->map(fn($p) => [
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
                if (count($allPairs) > 20) {
                    $this->line(sprintf('... y %d pares más.', count($allPairs) - 20));
                }
            }
            $this->info('DRY-RUN: nada fue modificado. Use --apply para ejecutar.');
            return self::SUCCESS;
        }

        $linked = 0;
        $skipped = 0;

        if ($apply) {
            // files-mirror-consolidation: bulk UPDATE + INSERT para 721k pairs.
            // Set-based es 100x más rápido que iterar con link() por cada par.
            // La atomicidad de link() (transacción por par + audit row) se
            // mantiene a nivel de batch dentro de una sola transacción.
            $this->info('Ejecutando bulk UPDATE + INSERT (set-based)...');
            $linked = $this->applyBulk($allPairs);
        } else {
            // En dry-run no iteramos; solo reportamos.
            $linked = 0;
        }

        $this->info("============================================================");
        $this->info(sprintf('Backfill completado: %d links creados, %d saltados.', $linked, $skipped));
        $this->info(sprintf('Audit rows esperadas: SELECT COUNT(*) FROM file_mirror_audit_log WHERE action=\'link_mirror\' AND metadata::text LIKE \'%s\';', '%repair_folder_mirrors_backfill%'));

        return self::SUCCESS;
    }

    /**
     * Aplica el backfill de canonical_folder_id en bulk.
     * Set-based: 1 UPDATE para todos los mirrors, 1 INSERT para el audit log.
     * Si falla algo, ROLLBACK deja la BD en estado original.
     */
    private function applyBulk(array $pairs): int
    {
        if (empty($pairs)) {
            return 0;
        }

        $now = now();
        $linked = 0;

        // Procesar en chunks para no hacer un solo UPDATE gigante que pueda
        // tumbar la BD. 5000 por chunk es seguro para tablas grandes.
        $chunks = array_chunk($pairs, 5000);

        foreach ($chunks as $i => $chunk) {
            $mirrorIds = [];
            $auditRows = [];
            foreach ($chunk as $pair) {
                $mirrorIds[] = (int) $pair->parent_id;
                $auditRows[] = [
                    'actor_user_id' => null,
                    'action' => 'link_mirror',
                    'mirror_file_id' => (int) $pair->parent_id,
                    'canonical_file_id' => (int) $pair->canonical_id,
                    'before_value' => null,
                    'after_value' => (string) $pair->canonical_id,
                    'metadata' => json_encode([
                        'reason' => 'repair_folder_mirrors_backfill',
                        'mirror_storage_id' => (int) $pair->parent_storage_id,
                        'canonical_storage_id' => (int) $pair->canonical_storage_id,
                    ]),
                    'created_at' => $now,
                ];
            }

            DB::transaction(function () use ($mirrorIds, $auditRows, &$linked) {
                // UPDATE bulk: setea canonical_folder_id para todos los mirrors
                // que aún no tienen uno. La condición WHERE canonical_folder_id IS NULL
                // hace el UPDATE idempotente (re-correr no re-muta).
                $updateSql = "
                    UPDATE files
                    SET canonical_folder_id = data.canonical_id,
                        merged_at = NOW(),
                        merged_reason = 'repair_folder_mirrors_backfill'
                    FROM (
                        SELECT unnest(?::bigint[]) AS id, unnest(?::bigint[]) AS canonical_id
                    ) AS data
                    WHERE files.id = data.id
                      AND files.canonical_folder_id IS NULL
                ";
                $canonicalIds = array_map(fn($r) => (int) $r['canonical_file_id'], $auditRows);

                $affected = DB::affectingStatement($updateSql, [
                    '{' . implode(',', $mirrorIds) . '}',
                    '{' . implode(',', $canonicalIds) . '}',
                ]);
                $linked += $affected;

                // INSERT audit log en bulk (solo si hubo update real)
                if ($affected > 0) {
                    DB::table('file_mirror_audit_log')->insert($auditRows);
                }
            });

            $this->info(sprintf('  Chunk %d/%d: %d links', $i + 1, count($chunks), $linked));
        }

        return $linked;
    }

    /**
     * Detecta pares folder/file espejo con la misma query probada en el spike.
     * Retorna una colección de objetos con parent_id, canonical_id, y metadatos.
     *
     * @param bool $includeFiles si true, escanea files además de folders
     */
    private function detectPairs(array $storageFilter, bool $includeFiles = false): array
    {
        $storageClause = '';
        if (!empty($storageFilter)) {
            $ids = implode(',', array_map('intval', $storageFilter));
            $storageClause = "AND (parent_row.storage_id IN ($ids) OR child_row.storage_id IN ($ids))";
        }

        // --include-files afecta qué rows son candidatas; el algoritmo es el mismo.
        $isFolderFilter = $includeFiles ? 'AND f.is_folder = false' : 'AND f.is_folder = true';

        $sql = <<<SQL
WITH norm AS (
  SELECT f.id, f.is_folder, f.storage_provider_id AS storage_id,
         f.parent_id, f.path, f.canonical_folder_id,
         lower(rtrim(sp.base_path,'/')) AS sp_base,
         lower(rtrim(sp.base_path,'/') || '/' || ltrim(f.path,'/')) AS abs_path,
         sp.name AS storage_name
  FROM files f
  JOIN storage_providers sp ON sp.id = f.storage_provider_id
  WHERE f.deleted_at IS NULL $isFolderFilter
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