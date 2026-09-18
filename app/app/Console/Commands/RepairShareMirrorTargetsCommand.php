<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Share;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Task 4.2):
 *
 * Repunta shares cuyo `file_id` apunta a un mirror (folder con
 * `canonical_folder_id IS NOT NULL`) hacia el canónico correspondiente.
 *
 * Caso origen: 36 folder shares detectados en el spike, 35 se repuntan
 * automáticamente; 1 par (#551/#552) entra en conflicto y queda fuera
 * por defecto.
 *
 * Por defecto (sin --include-duplicates): excluye shares donde ya existe
 * otro share apuntando al mismo canónico. Esa situación requiere decisión
 * explícita del operador (recomendación: revocar el duplicado).
 *
 * --revert: lee `file_mirror_audit_log` en orden cronológico inverso y
 * restaura cada `shares.file_id` al valor previo. Útil para rollback
 * post-deploy si la notificación a creadores falla o el operador decide
 * revertir.
 */
class RepairShareMirrorTargetsCommand extends Command
{
    protected $signature = 'shares:repair-mirror-targets
                            {--dry-run : Por defecto, solo reporta}
                            {--apply : Aplica el repoint}
                            {--revert : Revierte el repoint usando file_mirror_audit_log}
                            {--include-duplicates : Procesa también shares en conflicto con otro share sobre el mismo canónico}
                            {--storage=* : Limita a shares cuyo file_id pertenece a uno o más storage IDs}
                            {--batch=500 : Tamaño del lote}';

    protected $description = 'Repunta shares cuyo file_id es un mirror hacia el folder canónico';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $revert = (bool) $this->option('revert');
        $includeDuplicates = (bool) $this->option('include-duplicates');
        $storageFilter = (array) $this->option('storage');
        $batch = max(1, (int) $this->option('batch'));

        if ($revert) {
            return $this->revert($batch, $storageFilter);
        }

        $candidates = $this->detectCandidates($includeDuplicates, $storageFilter);

        $this->info(sprintf('Detectados %d shares a repuntar.', count($candidates)));

        if (!$apply) {
            $this->table(
                ['share_id', 'token', 'permissions', 'current_file_id', 'canonical_id', 'expires_at'],
                collect($candidates)->take(20)->map(fn($c) => [
                    $c->share_id,
                    substr($c->token, 0, 14) . '...',
                    $c->permissions,
                    $c->current_file_id,
                    $c->canonical_id,
                    $c->expires_at ?? '(sin vencimiento)',
                ])->all()
            );
            if (count($candidates) > 20) {
                $this->line(sprintf('... y %d más.', count($candidates) - 20));
            }
            $this->info('DRY-RUN: use --apply para ejecutar.');
            return self::SUCCESS;
        }

        $repointed = 0;
        $batches = array_chunk($candidates, $batch);

        foreach ($batches as $i => $batchShares) {
            $this->info(sprintf('Lote %d/%d (%d shares)...', $i + 1, count($batches), count($batchShares)));

            foreach ($batchShares as $cand) {
                $repointed += $this->repoint($cand);
            }

            if ($i < count($batches) - 1) {
                usleep(100_000);
            }
        }

        $this->info('============================================================');
        $this->info(sprintf('Repoint completado: %d shares actualizados.', $repointed));
        $this->info("Audit rows esperadas: SELECT COUNT(*) FROM file_mirror_audit_log WHERE action='repoint_share';");

        return self::SUCCESS;
    }

    /**
     * Detecta shares que necesitan repoint. Por defecto excluye conflictos
     * donde el canónico ya tiene otro share (caso #552 vs #551).
     */
    private function detectCandidates(bool $includeDuplicates, array $storageFilter = []): array
    {
        $storageClause = '';
        if (!empty($storageFilter)) {
            $ids = implode(',', array_map('intval', $storageFilter));
            $storageClause = "AND mirror.storage_provider_id IN ($ids)";
        }

        if ($includeDuplicates) {
            // Todos los shares sobre mirrors sin importar si hay conflicto
            $sql = <<<SQL
SELECT s.id AS share_id, s.token, s.permissions, s.expires_at,
       s.file_id AS current_file_id, mirror.canonical_folder_id AS canonical_id
FROM shares s
JOIN files mirror ON mirror.id = s.file_id
WHERE mirror.is_folder = true
  AND mirror.canonical_folder_id IS NOT NULL
  AND mirror.deleted_at IS NULL
  $storageClause
ORDER BY s.id
SQL;
        } else {
            // Excluir si ya hay otro share sobre el mismo canónico
            $sql = <<<SQL
SELECT s.id AS share_id, s.token, s.permissions, s.expires_at,
       s.file_id AS current_file_id, mirror.canonical_folder_id AS canonical_id
FROM shares s
JOIN files mirror ON mirror.id = s.file_id
WHERE mirror.is_folder = true
  AND mirror.canonical_folder_id IS NOT NULL
  AND mirror.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM shares s2
    WHERE s2.file_id = mirror.canonical_folder_id
      AND s2.id != s.id
  )
  $storageClause
ORDER BY s.id
SQL;
        }

        return DB::select($sql);
    }

    private function repoint(object $cand): int
    {
        $canonicalExists = File::where('id', $cand->canonical_id)
            ->whereNull('deleted_at')
            ->exists();

        if (!$canonicalExists) {
            $this->warn(sprintf('Share %d: canónico %d no existe, saltado.', $cand->share_id, $cand->canonical_id));
            return 0;
        }

        return DB::transaction(function () use ($cand) {
            $before = (string) $cand->current_file_id;
            $after = (string) $cand->canonical_id;

            $updated = Share::where('id', $cand->share_id)
                ->update(['file_id' => $cand->canonical_id]);

            if ($updated !== 1) {
                return 0;
            }

            DB::table('file_mirror_audit_log')->insert([
                'actor_user_id'    => null,
                'action'           => 'repoint_share',
                'mirror_file_id'   => $cand->current_file_id,
                'canonical_file_id' => $cand->canonical_id,
                'share_id'         => $cand->share_id,
                'before_value'     => $before,
                'after_value'      => $after,
                'metadata'         => json_encode([
                    'token' => $cand->token,
                    'permissions' => $cand->permissions,
                    'reason' => 'mirror_canonicalization',
                ]),
                'created_at'       => now(),
            ]);

            return 1;
        });
    }

    /**
     * Revierte todos los repoints hechos por este comando, leyendo
     * file_mirror_audit_log en orden cronológico inverso.
     *
     * El parámetro `$storageFilter` acota el revert a shares cuyo mirror vive
     * en uno de los storages dados. Sin filtro, TODOS los repoints del audit
     * log se revierten — útil para un rollback total, peligroso en harnesses
     * o pruebas contra producción.
     */
    private function revert(int $batch, array $storageFilter = []): int
    {
        $this->info('Revirtiendo repoints desde file_mirror_audit_log...');

        $query = DB::table('file_mirror_audit_log')
            ->where('action', 'repoint_share')
            ->whereNotNull('share_id');

        if (!empty($storageFilter)) {
            $ids = implode(',', array_map('intval', $storageFilter));
            $query->whereExists(function ($q) use ($ids) {
                $q->select(DB::raw(1))
                    ->from('files as m')
                    ->whereColumn('m.id', 'file_mirror_audit_log.mirror_file_id')
                    ->whereRaw("m.storage_provider_id IN ($ids)");
            });
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        $this->info(sprintf('Encontradas %d filas de repoint para revertir.', count($rows)));

        $reverted = 0;
        $batches = array_chunk($rows->all(), $batch);

        foreach ($batches as $i => $batchRows) {
            foreach ($batchRows as $row) {
                $reverted += DB::transaction(function () use ($row) {
                    $updated = Share::where('id', $row->share_id)
                        ->update(['file_id' => $row->before_value]);

                    if ($updated !== 1) {
                        return 0;
                    }

                    DB::table('file_mirror_audit_log')->insert([
                        'actor_user_id'    => null,
                        'action'           => 'revert_share',
                        'mirror_file_id'   => $row->mirror_file_id,
                        'canonical_file_id' => $row->canonical_file_id,
                        'share_id'         => $row->share_id,
                        'before_value'     => $row->after_value,
                        'after_value'      => $row->before_value,
                        'metadata'         => json_encode([
                            'reason' => 'revert_via_audit_log',
                            'original_audit_id' => $row->id,
                        ]),
                        'created_at'       => now(),
                    ]);

                    return 1;
                });
            }

            if ($i < count($batches) - 1) {
                usleep(100_000);
            }
        }

        $this->info(sprintf('Revert completado: %d shares restaurados.', $reverted));

        return self::SUCCESS;
    }
}