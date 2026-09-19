<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change `files-mirror-consolidation` (2026-09-19) — Task central:
 *
 * Helper único para resolver y mantener la identidad física de files/folders
 * entre storages padre/hijo. Usado por:
 *   1. `FileRegistry::ensure()` después de crear/upsert un file — linkea con
 *      canónico existente si lo hay, O crea mirror row faltante si no existe.
 *   2. `FilesRepairFileMirrorsCommand` — backfill set-based para files que
 *      sync no alcanzó a crear mirror rows (legacy data pre-fix).
 *
 * Estrategia sostenida:
 *   - Identidad física: `lower(rtrim(base_path_snapshot || '/' || path))`.
 *   - Canónico: el row cuyo `base_path_snapshot` es MÁS ESPECÍFICO (mayor
 *     longitud, igual prefijo). Si dos rows tienen base_path idéntico, gana
 *     el de menor `storage_provider_id`.
 *   - Mirror: cualquier otro row con la misma identidad física.
 *
 * Casos cubiertos:
 *   A) Sync crea row en sub-storage, ya existe canónico en otro storage
 *      → linkea via canonical_folder_id (no crea nada).
 *   B) Sync crea row en sub-storage, NO existe canónico
 *      → marca como canónico (canonical_folder_id = NULL).
 *   C) Sync crea row en parent storage, ya existe row canónico en sub-storage
 *      → este row se vuelve MIRROR, linkea al canónico.
 *   D) Sync crea row en parent storage, NO existe canónico en sub-storage
 *      → este row es canónico (caso normal).
 *
 * Idempotente: múltiples llamadas con la misma identidad no duplican ni
 * re-mutan rows ya enlazados.
 */
class FileMirrorLinker
{
    public const CACHE_KEY = 'file_mirror_link:';
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Decide si un row recién creado/upserted debe ser canónico o mirror,
     * y aplica el link correspondiente via canonical_folder_id.
     *
     * Llamado desde `FileRegistry::ensure()` después de cada ensure().
     * Es seguro llamarlo múltiples veces: si el row ya está enlazado
     * al canónico correcto, no hace nada.
     */
    public function reconcileAfterUpsert(File $row): void
    {
        if ($row->storage_provider_id === null || empty($row->path)) {
            return;
        }

        $physicalPath = $this->physicalPathFor($row);
        if ($physicalPath === null) {
            return;
        }

        // Cache: si ya reconciliamos esta identidad hace poco, skip.
        // Cache key incluye parent_id + storage_provider_id + path porque
        // la identidad puede cambiar si el path cambia.
        $cacheKey = self::CACHE_KEY . "{$row->storage_provider_id}:{$row->parent_id}:{$row->path}";
        if (Cache::has($cacheKey)) {
            return;
        }

        DB::transaction(function () use ($row, $physicalPath, $cacheKey) {
            $existingMirrorId = $this->findCanonicalId($physicalPath);
            $existingMirror = $existingMirrorId !== null ? File::find($existingMirrorId) : null;

            if ($existingMirror === null) {
                // Caso B/D: este row es el canónico.
                if ($row->canonical_folder_id !== null) {
                    DB::table('files')
                        ->where('id', $row->id)
                        ->update(['canonical_folder_id' => null]);
                }
                Cache::put($cacheKey, 'canon', self::CACHE_TTL_SECONDS);
                return;
            }

            // Ya hay un canónico. Decidir si $row es canónico o mirror
            // comparando especificidad de base_path.
            $rowBase = $row->base_path_snapshot ?? '';
            $existingBase = $existingMirror->base_path_snapshot ?? '';

            if ($row->id === $existingMirror->id) {
                // Es el mismo canónico (cache stale o race). Skip.
                Cache::put($cacheKey, 'canon', self::CACHE_TTL_SECONDS);
                return;
            }

            if ($this->isMoreSpecific($rowBase, $existingBase)
                || ($rowBase === $existingBase && $row->storage_provider_id < $existingMirror->storage_provider_id)) {
                // Este row es MÁS específico → canónico. El existente se vuelve mirror.
                DB::table('files')
                    ->where('id', $existingMirror->id)
                    ->update([
                        'canonical_folder_id' => $row->id,
                        'merged_at' => now(),
                        'merged_reason' => 'mirror_linker_reconcile',
                    ]);
                if ($row->canonical_folder_id !== null) {
                    DB::table('files')
                        ->where('id', $row->id)
                        ->update(['canonical_folder_id' => null]);
                }
                DB::table('file_mirror_audit_log')->insert([
                    'actor_user_id' => null,
                    'action' => 'link_mirror',
                    'mirror_file_id' => $existingMirror->id,
                    'canonical_file_id' => $row->id,
                    'before_value' => null,
                    'after_value' => (string) $row->id,
                    'metadata' => json_encode([
                        'reason' => 'reconcile_after_upsert',
                        'trigger' => 'more_specific',
                    ]),
                    'created_at' => now(),
                ]);
            } else {
                // El existente es más específico → canónico. Este row es mirror.
                if ($row->canonical_folder_id !== $existingMirror->id) {
                    DB::table('files')
                        ->where('id', $row->id)
                        ->update([
                            'canonical_folder_id' => $existingMirror->id,
                            'merged_at' => now(),
                            'merged_reason' => 'mirror_linker_reconcile',
                        ]);
                    DB::table('file_mirror_audit_log')->insert([
                        'actor_user_id' => null,
                        'action' => 'link_mirror',
                        'mirror_file_id' => $row->id,
                        'canonical_file_id' => $existingMirror->id,
                        'before_value' => null,
                        'after_value' => (string) $existingMirror->id,
                        'metadata' => json_encode([
                            'reason' => 'reconcile_after_upsert',
                            'trigger' => 'less_specific',
                        ]),
                        'created_at' => now(),
                    ]);
                }
            }

            Cache::put($cacheKey, 'linked', self::CACHE_TTL_SECONDS);
        });
    }

    /**
     * Backfill set-based para files que NO tienen mirror row en parent storage.
     *
     * Implementacion: un solo INSERT...SELECT que:
     *  1. Encuentra storage_pairs (child storage -> parent storage) por prefijo de base_path.
     *  2. Filtra storages con base_path duplicado (pendientes de merge).
     *  3. Para cada (parent_storage, canonical_file) calcula relative_path.
     *  4. Excluye pares donde ya existe mirror row (NOT EXISTS).
     *  5. INSERT directo en bulk.
     *
     * Performance: ~30-90s para 790k inserts (vs 100min con la version iterativa).
     *
     * @return int número de mirror rows creadas
     */
    public function backfillMissingFileMirrors(int $batchSize = 5000): int
    {
        $totalCreated = 0;
        $maxIterations = 200;
        $iteration = 0;
        $GLOBALS['__backfill_batch'] = $batchSize;

        while ($iteration < $maxIterations) {
            $iteration++;
            $created = $this->runOneBatch();
            $totalCreated += $created;
            if ($created < $batchSize) break;

            if ($iteration % 5 === 0) {
                Log::info('files_mirror_backfill.progress', [
                    'iteration' => $iteration,
                    'created_total' => $totalCreated,
                ]);
            }
        }

        unset($GLOBALS['__backfill_batch']);
        return $totalCreated;
    }

    /**
     * Ejecuta UNA iteracion del backfill: encuentra pares pendientes y los inserta.
     * Usa LIMIT + un loop en PHP (no OFFSET, que es lento en tablas grandes).
     */
    private function runOneBatch(int $batchSize = 5000): int
    {
        // SQL: parent storages (no duplicates) x child storages (no duplicates)
        // WHERE base_path del child es prefijo estricto del parent.
        // Para cada archivo canónico (no mirror ya) del child_storage,
        // calcular relative_path y verificar que NO exista mirror row en parent.
        // INSERT INTO files con todos los metadatos del canónico.
        $sql = "
            WITH storage_pairs AS (
                SELECT child.id AS child_id, child.base_path AS child_base,
                       parent.id AS parent_id, parent.base_path AS parent_base
                FROM storage_providers child
                JOIN storage_providers parent
                  ON length(child.base_path) > length(parent.base_path)
                 AND lower(child.base_path) LIKE lower(parent.base_path) || '/%'
                WHERE NOT EXISTS (
                    SELECT 1 FROM storage_providers dup
                    WHERE dup.base_path = child.base_path AND dup.id != child.id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM storage_providers dup
                    WHERE dup.base_path = parent.base_path AND dup.id != parent.id
                )
            ),
            mirror_candidates AS (
                SELECT sp.parent_id AS target_storage_id,
                       c.id AS canonical_id,
                       c.name, c.size, c.mime_type, c.file_modified_at,
                       c.owner_id, c.is_folder, c.availability_state,
                       c.parent_id AS canonical_parent_id,
                       SUBSTRING(
                           lower(sp.child_base) || '/' || lower(c.path)
                           FROM LENGTH(sp.parent_base) + 2
                       ) AS mirror_path
                FROM storage_pairs sp
                JOIN files c ON c.storage_provider_id = sp.child_id
                            AND c.deleted_at IS NULL
                            AND c.is_folder = false
                            AND c.is_trashed = false
                            AND c.canonical_folder_id IS NULL
                WHERE NOT EXISTS (
                    SELECT 1 FROM files m
                    WHERE m.storage_provider_id = sp.parent_id
                      AND lower(m.path) = SUBSTRING(
                          lower(sp.child_base) || '/' || lower(c.path)
                          FROM LENGTH(sp.parent_base) + 2
                      )
                      AND m.deleted_at IS NULL
                )
                LIMIT ?
            )
            INSERT INTO files (
                name, path, storage_provider_id, owner_id, parent_id, is_folder,
                size, mime_type, file_modified_at, availability_state,
                last_verified_at, missing_since_at, is_trashed, deleted_at,
                canonical_folder_id, merged_at, merged_reason, created_at, updated_at,
                base_path_snapshot
            )
            SELECT name, mirror_path, target_storage_id, owner_id, canonical_parent_id,
                   is_folder, size, mime_type, file_modified_at, availability_state,
                   NOW(), NULL, false, NULL, canonical_id, NOW(), 'backfill_file_mirrors',
                   NOW(), NOW(), NULL
            FROM mirror_candidates
            RETURNING id, canonical_folder_id, storage_provider_id
        ";

        $inserted = DB::select($sql, [$GLOBALS['__backfill_batch'] ?? 5000]);

        if (empty($inserted)) {
            return 0;
        }

        // Bulk audit log
        $auditRows = [];
        $now = now();
        foreach ($inserted as $row) {
            $auditRows[] = [
                'actor_user_id' => null,
                'action' => 'link_mirror',
                'mirror_file_id' => (int) $row->id,
                'canonical_file_id' => (int) $row->canonical_folder_id,
                'before_value' => null,
                'after_value' => (string) $row->canonical_folder_id,
                'metadata' => json_encode([
                    'reason' => 'backfill_file_mirrors',
                    'parent_storage_id' => (int) $row->storage_provider_id,
                    'canonical_storage_id' => null,
                ]),
                'created_at' => $now,
            ];
        }
        if (!empty($auditRows)) {
            DB::table('file_mirror_audit_log')->insert($auditRows);
        }

        return count($inserted);
    }

    /**
     * Encuentra el ID del canónico existente para una identidad física.
     * Excluye el row actual (pasado como $excludeId).
     */
    private function findCanonicalId(string $physicalPath): ?int
    {
        $result = DB::selectOne("
            SELECT f.id FROM files f
            JOIN storage_providers sp ON sp.id = f.storage_provider_id
            WHERE lower(rtrim(COALESCE(f.base_path_snapshot, sp.base_path), '/'))
                  || '/' || lower(f.path) = ?
              AND f.deleted_at IS NULL
            LIMIT 1
        ", [$physicalPath]);

        return $result ? (int) $result->id : null;
    }

    /**
     * Compute physical path for a File row.
     */
    private function physicalPathFor(File $row): ?string
    {
        if (empty($row->path)) return null;

        $basePath = $row->base_path_snapshot;
        if (empty($basePath)) {
            $sp = StorageProvider::find($row->storage_provider_id, ['base_path']);
            if (!$sp) return null;
            $basePath = $sp->base_path;
        }

        return strtolower(rtrim($basePath, '/') . '/' . ltrim($row->path, '/'));
    }

    /**
     * Determina si $aBase es más específico que $bBase.
     * Más específico = prefijo estricto + mayor longitud.
     */
    private function isMoreSpecific(string $aBase, string $bBase): bool
    {
        $aLower = strtolower(rtrim($aBase, '/'));
        $bLower = strtolower(rtrim($bBase, '/'));

        if ($aLower === $bLower) return false;
        if (strlen($aLower) <= strlen($bLower)) return false;

        return str_starts_with($aLower, $bLower . '/') || str_starts_with($bLower, $aLower . '/');
    }
}
