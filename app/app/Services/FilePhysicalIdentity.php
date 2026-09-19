<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Change `files-mirror-elimination` (2026-09-19):
 *
 * Servicio central de identidad física de un file/folder. La identidad se
 * define como `lower(rtrim(base_path || '/' || path))` y se resuelve EN
 * TIEMPO DE LECTURA. No hay columnas materializadas que mantener.
 *
 * Historia: el change `files-physical-folder-identity` (2026-09-17) introdujo
 * la columna `files.canonical_folder_id` para materializar la relación
 * canónico/mirror. Los backfills produjeron 1.455.875 mirror rows de las
 * cuales NINGUNA resolvía a un archivo real en disco (94% sin
 * `base_path_snapshot`). Este servicio las reemplaza resolviendo por path
 * físico.
 *
 * El servicio NO toca disco, NO toca shares, NO muta paths. Solo lee.
 */
class FilePhysicalIdentity
{
    public const CACHE_PREFIX = 'file:canonical:';

    public const CACHE_TTL_SECONDS = 300;

    /**
     * Clave de cache para una identidad física. Una entrada por identidad (no
     * por fila): todos los rows equivalentes comparten la resolución.
     */
    public static function cacheKeyFor(string $normalized): string
    {
        return self::CACHE_PREFIX . sha1($normalized);
    }

    /**
     * Devuelve el row canónico para un file/folder.
     *
     * Resolución en dos niveles, en orden:
     *
     *  1. **Identidad física** (primario): el canónico es la fila cuyo
     *     `base_path` es MÁS específico (prefijo más largo) entre todas las que
     *     comparten la misma identidad física. Es la fuente de verdad porque se
     *     deriva del disco.
     *
     *  2. **`canonical_folder_id`** (fallback): si la identidad física del row
     *     no tiene equivalentes (típicamente porque su `path` quedó corrupto por
     *     un backfill defectuoso — el caso `backfill_v4_subfile`, que copió el
     *     path del canónico sin rebasarlo al storage propio), se usa el FK
     *     materializado, que sí apunta al canónico correcto. Este fallback
     *     desaparece cuando el comando de retiro deja la columna en 0.
     *
     * Si el input ya es el canónico (o no hay otras filas equivalentes y no hay
     * FK), devuelve el mismo input. Nunca retorna null para un input válido.
     *
     * Cacheado en Redis con TTL 300s, keyed por identidad física. El cache se
     * invalida en `FileObserver` cuando cambian `storage_provider_id` o `path`.
     */
    public function canonicalFor(File $file): ?File
    {
        $normalized = $this->physicalPathOf($file);

        if ($normalized !== null) {
            $cacheKey = self::cacheKeyFor($normalized);
            $cachedId = Cache::get($cacheKey);

            if ($cachedId !== null) {
                if ((int) $cachedId !== (int) $file->id) {
                    $cached = File::find((int) $cachedId);

                    if ($cached !== null) {
                        return $cached;
                    }
                }
                // El canónico cacheado fue borrado: recomputar.
                Cache::forget($cacheKey);
            }

            $canonicalId = $this->resolveCanonicalId($normalized);

            // Resolución por identidad física válida solo si apunta a OTRA fila:
            // si el mejor candidato es el propio row, entonces su path no tiene
            // equivalentes y la identidad física no aporta información.
            if ($canonicalId !== null && (int) $canonicalId !== (int) $file->id) {
                Cache::put($cacheKey, $canonicalId, self::CACHE_TTL_SECONDS);
                return File::find((int) $canonicalId) ?? $file;
            }
        }

        $viaLink = $this->resolveViaMaterializedLink($file);

        if ($viaLink !== null) {
            if ($normalized !== null) {
                Cache::put(self::cacheKeyFor($normalized), $viaLink->id, self::CACHE_TTL_SECONDS);
            }
            return $viaLink;
        }

        if ($normalized !== null) {
            Cache::put(self::cacheKeyFor($normalized), $file->id, self::CACHE_TTL_SECONDS);
        }

        return $file;
    }

    /**
     * Fallback: sigue `canonical_folder_id` cuando la resolución por identidad
     * física no encontró equivalentes (path corrupto). Ignora el FK si el
     * canónico fue borrado o si el row es degradado de forma que el FK apunte a
     * sí mismo.
     */
    private function resolveViaMaterializedLink(File $file): ?File
    {
        $linkId = $file->canonical_folder_id;

        if ($linkId === null || (int) $linkId === (int) $file->id) {
            return null;
        }

        $canonical = File::find((int) $linkId);

        if ($canonical === null) {
            return null;
        }

        return $canonical;
    }

    /**
     * Devuelve todos los rows que representan la misma identidad física:
     * el canónico + sus equivalentes. Si el input no tiene identidad física
     * calculable, devuelve solo el input.
     */
    public function siblingsOf(File $file): Collection
    {
        $normalized = $this->physicalPathOf($file);

        if ($normalized === null) {
            return collect([$file]);
        }

        $ids = $this->equivalentIds($normalized);

        if (empty($ids)) {
            return collect([$file]);
        }

        $rows = File::whereIn('id', $ids)->get();

        return $rows->isEmpty() ? collect([$file]) : $rows;
    }

    /**
     * Identidad física del row: `lower(rtrim(base_path || '/' || path))`.
     * Usa `base_path_snapshot` y cae a `storage_providers.base_path` cuando el
     * snapshot no está poblado (744.126 filas canónicas no lo tienen).
     */
    public function physicalPathOf(File $row): ?string
    {
        if ($row->path === null || $row->path === '') {
            return null;
        }

        $basePath = $row->base_path_snapshot;

        if (empty($basePath) && $row->storage_provider_id) {
            $basePath = DB::table('storage_providers')
                ->where('id', $row->storage_provider_id)
                ->value('base_path');
        }

        if (empty($basePath)) {
            return null;
        }

        return strtolower(rtrim((string) $basePath, '/') . '/' . ltrim((string) $row->path, '/'));
    }

    /**
     * Devuelve el id del canónico para una identidad física, o null si no hay
     * ninguna fila viva. El canónico es la fila cuyo `base_path` es el más
     * largo; empate → menor `id`.
     */
    private function resolveCanonicalId(string $normalized): ?int
    {
        $ids = $this->equivalentIds($normalized, 1);

        return $ids[0] ?? null;
    }

    /**
     * Ids de todos los rows vivos con la identidad física dada, ordenados por
     * especificidad de `base_path` descendente y luego por `id` ascendente.
     *
     * Dos queries, no un JOIN, para que ambas puedan usar índices:
     *
     *  1. Rows con `base_path_snapshot` poblado — usa el índice funcional
     *     `files_physical_path_normalized_idx` sobre la expresión textual
     *     `LOWER(RTRIM(base_path_snapshot, '/') || '/' || LTRIM(path, '/'))`.
     *     Un índice funcional no puede referenciar otra tabla, por eso no hay
     *     JOIN aquí.
     *  2. Rows sin snapshot — resuelve el base_path con un JOIN a
     *     `storage_providers`. Este subconjunto es chico una vez corrido
     *     `files:resync-base-path-snapshots --apply`, así que el seq scan
     *     acotado es aceptable.
     *
     * @return int[]
     */
    private function equivalentIds(string $normalized, ?int $limit = null): array
    {
        $ids = [];

        $withSnapshot = DB::select("
            SELECT f.id, LENGTH(RTRIM(f.base_path_snapshot, '/')) AS base_len
            FROM files f
            WHERE f.deleted_at IS NULL
              AND f.path IS NOT NULL
              AND f.path <> ''
              AND f.base_path_snapshot IS NOT NULL
              AND f.base_path_snapshot <> ''
              AND LOWER(RTRIM(f.base_path_snapshot, '/') || '/' || LTRIM(f.path, '/')) = ?
        ", [$normalized]);

        foreach ($withSnapshot as $row) {
            $ids[(int) $row->id] = (int) $row->base_len;
        }

        // Solo consultamos el fallback si el llamador necesita más candidatos
        // que los ya encontrados (LIMIT) o si no encontró ninguno.
        if ($limit === null || count($ids) < $limit) {
            $withoutSnapshot = DB::select("
                SELECT f.id, LENGTH(RTRIM(sp.base_path, '/')) AS base_len
                FROM files f
                JOIN storage_providers sp ON sp.id = f.storage_provider_id
                WHERE f.deleted_at IS NULL
                  AND f.path IS NOT NULL
                  AND f.path <> ''
                  AND (f.base_path_snapshot IS NULL OR f.base_path_snapshot = '')
                  AND sp.base_path IS NOT NULL
                  AND sp.base_path <> ''
                  AND LOWER(RTRIM(sp.base_path, '/') || '/' || LTRIM(f.path, '/')) = ?
            ", [$normalized]);

            foreach ($withoutSnapshot as $row) {
                $ids[(int) $row->id] = (int) $row->base_len;
            }
        }

        // Más específico (base_path más largo) primero; empate → id ascendente.
        $idList = array_keys($ids);
        usort($idList, function (int $a, int $b) use ($ids) {
            if ($ids[$a] !== $ids[$b]) {
                return $ids[$b] <=> $ids[$a];
            }
            return $a <=> $b;
        });

        if ($limit !== null) {
            $idList = array_slice($idList, 0, max(1, $limit));
        }

        return $idList;
    }
}
