<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-share-folder-canonical-wiring` (Task 1.1):
 *
 * Lista los archivos visibles bajo una identidad de folder, considerando
 * los rows en storages padres cuyo `parent_id` apunte al mirror equivalente
 * (cambio `files-physical-folder-identity`, PR 1).
 *
 * Es el equivalente público de `StorageSyncService::currentListing()` pero
 * simplificado: sin cache, sin invalidación, sin weight-by-storage
 * (asumimos que cada archivo físico vive en un solo row, gracias al
 * backfill de PR 1).
 *
 * Uso:
 *   $rows = app(FolderListingService::class)->listContents($folder);
 *
 * La identidad de `$folder` puede ser canónica o mirror; el servicio opera
 * sobre el `$folder->id` que reciba. Los callers deben canonizar ANTES de
 * invocar (típicamente via `FilePhysicalIdentity::canonicalFor()`).
 */
class FolderListingService
{
    /**
     * Devuelve los rows visibles bajo el folder `$folder` (canonical o mirror).
     *
     * @return Collection<int, File>
     */
    public function listContents(File $folder, ?int $limit = null): Collection
    {
        // Resolver el set de parent_ids que apuntan a esta identidad de folder.
        // - El folder canónico vive en su storage_id; parent_id directo.
        // - Los mirrors viven en storages padres; parent_id apunta al row mirror,
        //   pero como el folder ES el row mirror, parent_id es parent_id del mirror
        //   que apunta a este folder como padre.
        //
        // La lógica completa:
        //   1. Rows con parent_id = $folder->id (hijos directos del folder en su storage)
        //   2. Rows con parent_id = mirror_id para cada mirror equivalente
        //
        // Pero los archivos físicos viven en UN SOLO storage (PR 1 los consolidó
        // en el sub-storage via files:repair-delegation-leak). Por lo tanto, en
        // el caso típico, los archivos están bajo el folder canónico, y los
        // mirror rows tienen 0 hijos.
        //
        // Sin embargo, si un sync futuro crea archivos bajo el mirror en lugar
        // del canónico (caso edge, ver change `mis-archivos-substorage-orphan-repair`),
        // queremos que el listado los incluya.

        // Encontrar todos los row ids que representan esta identidad de folder.
        $folderIds = $this->resolveFolderIds($folder);

        $query = File::query()
            ->whereIn('parent_id', $folderIds)
            ->where('is_trashed', false)
            ->where(function ($q) {
                $q->whereNull('availability_state')
                  ->orWhere('availability_state', '!=', 'missing');
            });

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query
            ->orderBy('is_folder', 'desc')
            ->orderBy('name')
            ->get();

        // Dedup defensivo: si dos storages tienen el mismo archivo físico con
        // mismo nombre (caso edge pre-PR1), preferimos el row del storage con
        // `base_path` más largo (= sub-storage más específico).
        return $this->dedupeByName($rows);
    }

    /**
     * Devuelve el set de `files.id` que representan esta identidad de folder.
     *
     * Dos mecanismos:
     *  1. **Mirror identity** (PR 1): canonical_id + IDs de rows con `canonical_folder_id`
     *     apuntando al canonical. Funciona para mirrors explícitamente vinculados.
     *  2. **Physical-path equivalence** (este fix): cualquier row folder con el MISMO
     *     `physical_path_normalized` (= base_path_snapshot + path). Funciona para
     *     folders que viven en distintos storages con el mismo path físico absoluto
     *     pero que NO fueron enlazados como mirrors (caso típico: un auto-sync movió
     *     archivos al sub-storage más específico y los folders equivalentes quedaron
     *     huérfanos de linkage).
     *
     * La unión cubre ambos casos. El caller hace `parent_id IN $ids` y obtiene los
     * hijos directos de CUALQUIER folder equivalente, sean mirrors o solo path-iguales.
     *
     * @return int[]
     */
    public function resolveFolderIds(File $folder): array
    {
        $ids = [];

        // 1) Mirror identity: canonical + mirrors vinculados.
        $canonical = $folder->isFolderMirror()
            ? File::find($folder->canonical_folder_id)
            : $folder;

        if ($canonical) {
            $ids[] = $canonical->id;
        }

        $mirrorIds = File::where('canonical_folder_id', $folder->id)
            ->orWhere('canonical_folder_id', $canonical?->id)
            ->pluck('id')
            ->all();

        $ids = array_merge($ids, $mirrorIds);

        // 2) Physical-path equivalence: otros folders en otros storages que apuntan
        // al MISMO path físico (base_path_snapshot + path). Esto cubre el caso
        // real visto en share #764: el share apuntaba al folder en storage 5
        // (vacío tras un sync), pero los archivos físicos viven en storage 34
        // (sub-storage más específico) bajo un folder con el mismo path absoluto
        // pero sin linkage de mirror. Sin este paso el listado del share sale vacío.
        $targetNormalized = $folder->physicalPathNormalized();
        if ($targetNormalized !== null && $targetNormalized !== '(orphan)') {
            $equivalentIds = File::where('is_folder', true)
                ->where('is_trashed', false)
                ->whereNotNull('base_path_snapshot')
                ->whereNotNull('path')
                ->whereRaw(
                    "LOWER(RTRIM(COALESCE(base_path_snapshot, '') || '/' || COALESCE(path, ''))) = ?",
                    [$targetNormalized]
                )
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $equivalentIds);
        }

        return array_map('intval', array_unique($ids));
    }

    /**
     * Deduplica rows por nombre, prefiriendo el del storage con base_path más largo.
     */
    private function dedupeByName(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        // Cache de base_path por storage_id para no repetir queries.
        $basePaths = [];
        foreach ($rows as $r) {
            $sid = $r->storage_provider_id;
            if (!isset($basePaths[$sid])) {
                $basePaths[$sid] = StorageProvider::where('id', $sid)->value('base_path') ?? '';
            }
        }

        $byName = [];
        foreach ($rows as $r) {
            $name = $r->name;
            $currentWeight = strlen($basePaths[$r->storage_provider_id] ?? '');
            if (!isset($byName[$name])) {
                $byName[$name] = ['row' => $r, 'weight' => $currentWeight];
                continue;
            }
            if ($currentWeight > $byName[$name]['weight']) {
                $byName[$name] = ['row' => $r, 'weight' => $currentWeight];
            }
        }

        return collect(array_values(array_map(fn ($e) => $e['row'], $byName)))
            ->sortBy([['is_folder', 'desc'], ['name', 'asc']])
            ->values();
    }
}