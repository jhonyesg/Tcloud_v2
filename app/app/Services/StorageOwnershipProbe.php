<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Log;

/**
 * Detecta hijos directos del `base_path` de un StorageProvider que son
 * inaccesibles al usuario PHP-FPM por permisos restrictivos del filesystem.
 *
 * Complementa a `StorageOwnershipAuditCommand` (que detecta paths con owner
 * distinto al usuario PHP-FPM). El audit por `find` corre como `www` y tropieza
 * con EACCES al intentar descender en directorios `700` ajenos, por lo que
 * esos paths son invisibles al reporte. Este probe PHP-based captura
 * explícitamente el caso de "directorio bloqueado por permisos" desde el
 * primer nivel que sí es legible.
 *
 * Limitaciones conocidas:
 *  - Scope: solo hijos DIRECTOS del base_path. No recursa (intencional,
 *    porque un walker recursivo correría contra el mismo EACCES).
 *  - El cross-check con BD puede omitir directorios recién creados que aún
 *    no fueron indexados (race window corto entre creación de dir y scan).
 *
 * Referencia: change `storage-readability-audit-and-repair` (OpenSpec).
 */
class StorageOwnershipProbe
{
    /**
     * Denylist invariante de nombres que NO se reportan como untraversable.
     * Cubre metadata del sistema operativo (Windows NTFS, paperpaperas).
     */
    public const DENYLIST = [
        'System Volume Information',
        '$RECYCLE.BIN',
        '.Recycle_bin',
        '.Trash',
        '.Trash-1000',
        'lost+found',
    ];

    /**
     * Detecta hijos directos del `base_path` que son directorios donde
     * `is_dir()` resuelve verdadero pero `is_readable()` falla.
     *
     * @return list<array{path: string, mode: int, owner: int, group: int, bd_count: int, is_metadata: bool}>
     */
    public static function detectUntravsersable(StorageProvider $storage, int $limit = 100): array
    {
        $basePath = $storage->base_path;
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return [];
        }

        $entries = @scandir($basePath);
        if ($entries === false) {
            Log::warning('storage.ownership_probe.scandir_failed', [
                'storage_id' => $storage->id,
                'base_path' => $basePath,
            ]);
            return [];
        }

        $findings = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (self::isDenied($entry)) {
                continue;
            }

            $childPath = rtrim($basePath, '/') . '/' . $entry;
            if (!is_dir($childPath)) {
                continue;
            }

            if (is_readable($childPath)) {
                continue;
            }

            $stat = @stat($childPath);
            $mode = $stat ? ($stat['mode'] & 0777) : 0;
            $owner = $stat ? $stat['uid'] : 0;
            $group = $stat ? $stat['gid'] : 0;

            $bdCount = File::query()
                ->where('storage_provider_id', $storage->id)
                ->where('path', 'LIKE', $entry . '/%')
                ->count();

            $findings[] = [
                'path' => $childPath,
                'name' => $entry,
                'mode' => $mode,
                'owner' => $owner,
                'group' => $group,
                'bd_count' => $bdCount,
                'is_metadata' => false,
            ];

            if (count($findings) >= $limit) {
                break;
            }
        }

        return $findings;
    }

    /**
     * Detecta hijos directos cuyo nombre cae en la denylist (para reportar
     * `metadata_skip` en el comando audit, sin reparar).
     *
     * @return list<string>
     */
    public static function detectMetadata(StorageProvider $storage): array
    {
        $basePath = $storage->base_path;
        if (!is_dir($basePath) || !is_readable($basePath)) {
            return [];
        }

        $entries = @scandir($basePath);
        if ($entries === false) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (self::isDenied($entry)) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Filtra un set de paths (resultado de `detectUntravsersable`) por la
     * denylist. Devuelve el sub-set sobreviviente y el conteo de filtrados.
     *
     * @param list<string> $pathsOrNames
     * @return array{allowed: list<string>, denied: list<string>}
     */
    public static function applyDenylist(array $pathsOrNames): array
    {
        $allowed = [];
        $denied = [];
        foreach ($pathsOrNames as $p) {
            $basename = basename($p);
            if (self::isDenied($basename)) {
                $denied[] = $basename;
            } else {
                $allowed[] = $p;
            }
        }
        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * Verifica si un nombre (basename) cae en la denylist.
     */
    public static function isDenied(string $basename): bool
    {
        if (in_array($basename, self::DENYLIST, true)) {
            return true;
        }
        if (str_starts_with($basename, '.')) {
            return true;
        }
        return false;
    }
}