<?php

namespace App\Services\MisArchivos;

use App\Models\StorageProvider;
use App\Models\User;
use App\Services\MountGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FilesystemListingService
{
    public function __construct(
        private FilesystemPermissionGuard $permissions,
        private MountGuard $mountGuard,
    ) {}

    /**
     * @return array{
     *   files: list<array<string,mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, has_more:bool},
     *   breadcrumbs: list<array<string,mixed>>,
     *   meta: array<string,mixed>,
     *   error?: string
     * }
     */
    public function list(int $storageId, ?string $subPath, int $userId, ?int $limit = null): array
    {
        $storage = StorageProvider::find($storageId);
        if (!$storage) {
            return $this->emptyResponse('storage_not_found');
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->emptyResponse('user_not_found');
        }

        if (!$this->permissions->canAccess($storageId, $userId, 'read')) {
            $this->permissions->deny($userId, $storageId, $subPath);
            return $this->emptyResponse('permission_denied');
        }

        $absolute = $this->resolveAbsolutePath($storage, $subPath);

        if (!$this->isPathWithinBase($storage->base_path, $absolute)) {
            Log::warning('mis_archivos.fs_path_outside_base', [
                'storage_id' => $storageId,
                'absolute' => $absolute,
                'base' => $storage->base_path,
            ]);
            return $this->emptyResponse('path_outside_base');
        }

        // CRITICO: en NFS con mount "hard", stat()/is_dir() pueden bloquear PHP-FPM
        // indefinidamente. MountGuard ya valida /proc/self/mounts primero y evita el
        // stat() en mounts conocidos. Lo invocamos ANTES de tocar el filesystem.
        if (($detached = $this->mountGuard->detachedAncestor($absolute)) !== null) {
            Log::warning('mis_archivos.mount_detached', [
                'storage_id' => $storageId,
                'mount_point' => $detached,
            ]);
            return $this->emptyResponseWithBreadcrumbs($storageId, $subPath, 'mount_detached');
        }

        if (!$this->isPathAccessible($absolute)) {
            $errCode = $this->probePathError($absolute);
            Log::warning('mis_archivos.fs_io_error', [
                'storage_id' => $storageId,
                'absolute' => $absolute,
                'reason' => $errCode ?? 'path_missing_or_unreadable',
            ]);
            return $this->emptyResponseWithBreadcrumbs($storageId, $subPath, $errCode ?? 'path_missing');
        }

        $maxLimit = $limit ?? (int) config('mis_archivos.listing_limit', 500);
        $entries = $this->scanSafely($absolute);
        if ($entries === null) {
            Log::warning('mis_archivos.fs_io_error', [
                'storage_id' => $storageId,
                'absolute' => $absolute,
                'reason' => 'scandir_failed_or_timeout',
            ]);
            return $this->emptyResponseWithBreadcrumbs($storageId, $subPath, 'path_missing');
        }

        $total = 0;
        $files = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            $total++;
            if (count($files) >= $maxLimit) continue;

            $entryAbs = $absolute . '/' . $name;
            $stat = @stat($entryAbs);
            if ($stat === false) {
                Log::warning('mis_archivos.fs_io_error', [
                    'storage_id' => $storageId,
                    'absolute' => $entryAbs,
                    'reason' => 'stat_failed',
                ]);
                continue;
            }

            $files[] = $this->entryToArray($storageId, $subPath, $name, $stat, $entryAbs, $userId);
        }

        $permission = $this->permissions->permissionsFor($storageId, $userId);

        return [
            'files' => $files,
            'pagination' => [
                'page' => 1,
                'per_page' => $maxLimit,
                'total' => $total,
                'has_more' => $total > $maxLimit,
            ],
            'breadcrumbs' => $this->parentChain($storageId, $subPath),
            'meta' => [
                'fs_primary' => true,
                'storage_id' => $storageId,
                'permission' => $permission,
                'matcher_last_run' => Cache::get('mis_archivos:matcher_last_run'),
            ],
        ];
    }

    /**
     * @return list<array{name:string, path:string, storage_provider_id:int, has_file_id:bool, file_id:?int}>
     */
    public function parentChain(int $storageId, ?string $subPath): array
    {
        $storage = StorageProvider::find($storageId);
        if (!$storage) {
            return [];
        }

        $segments = [];
        $clean = trim((string) $subPath, '/');
        if ($clean === '') {
            return [[
                'name' => $storage->name,
                'path' => '',
                'storage_provider_id' => $storageId,
                'has_file_id' => false,
                'file_id' => null,
                'is_root' => true,
            ]];
        }

        $parts = explode('/', $clean);
        $accum = '';
        foreach ($parts as $i => $seg) {
            if ($i === 0 && $seg === '') continue;
            $accum = $accum === '' ? $seg : ($accum . '/' . $seg);
            $fileId = $this->resolveFileId($storageId, $accum);
            $segments[] = [
                'name' => $seg,
                'path' => $accum,
                'storage_provider_id' => $storageId,
                'has_file_id' => $fileId !== null,
                'file_id' => $fileId,
                'is_root' => false,
            ];
        }

        array_unshift($segments, [
            'name' => $storage->name,
            'path' => '',
            'storage_provider_id' => $storageId,
            'has_file_id' => false,
            'file_id' => null,
            'is_root' => true,
        ]);

        return $segments;
    }

    public function resolveFileId(int $storageId, string $path): ?int
    {
        if ($path === '') return null;

        $cacheKey = "mis_archivos:path_lookup:{$storageId}:" . md5($path);
        $ttl = (int) config('mis_archivos.path_cache_ttl', 60);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '__null__' ? null : (int) $cached;
        }

        $id = DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('path', $path)
            ->value('id');

        Cache::put($cacheKey, $id === null ? '__null__' : (int) $id, $ttl);

        return $id !== null ? (int) $id : null;
    }

    public function invalidateFileIdCache(int $storageId, string $path): void
    {
        Cache::forget("mis_archivos:path_lookup:{$storageId}:" . md5($path));
    }

    private function entryToArray(
        int $storageId,
        ?string $subPath,
        string $name,
        array $stat,
        string $absolute,
        int $userId,
    ): array {
        $relPath = ($subPath === null || $subPath === '') ? $name : ($subPath . '/' . $name);
        $fileId = $this->resolveFileId($storageId, $relPath);
        $isFolder = ($stat['mode'] & 040000) === 040000;

        $permission = $this->permissions->permissionsFor($storageId, $userId);

        return [
            'id' => $fileId,
            'has_file_id' => $fileId !== null,
            'name' => $name,
            'path' => $relPath,
            'absolute_path' => $absolute,
            'size' => $isFolder ? null : (int) $stat['size'],
            'mime_type' => $isFolder ? 'folder' : $this->guessMime($name),
            'is_folder' => $isFolder,
            'file_modified_at' => CarbonImmutable::createFromTimestamp($stat['mtime'])->toIso8601String(),
            'source' => $fileId ? 'mixed' : 'filesystem',
            'storage_provider_id' => $storageId,
            'permissions' => $permission,
            'actions' => $this->permissions->actionsFor($permission, $isFolder),
        ];
    }

    private function resolveAbsolutePath(StorageProvider $storage, ?string $subPath): string
    {
        $base = rtrim($storage->base_path, '/');
        $sub = trim((string) $subPath, '/');
        if ($sub === '') return $base;
        return $base . '/' . $sub;
    }

    private function isPathWithinBase(string $base, string $absolute): bool
    {
        // CRITICO: NO llamar realpath() sobre el absolute path: en NFS hard-mount
        // puede bloquear PHP-FPM indefinidamente. Verificamos containment solo
        // con strings (las dos rutas ya vienen normalizadas del caller).
        $baseClean = rtrim($base, '/');
        $targetClean = rtrim($absolute, '/');

        if ($targetClean === $baseClean) return true;
        return str_starts_with($targetClean . '/', $baseClean . '/');
    }

    private function isPathAccessible(string $absolute): bool
    {
        $normalized = rtrim($absolute, '/');

        // Walk up looking for any path that IS a known mount point in
        // /proc/self/mounts (lectura LOCAL, nunca bloquea). Si lo encontramos,
        // asumimos que la sub-ruta es accesible. Si NO, es path puramente local
        // y podemos hacer is_dir() sin riesgo de NFS hard-mount blocking.
        $mounts = $this->mountGuard->mounts();
        $cursor = $normalized;
        while ($cursor !== '' && $cursor !== '/') {
            if (array_key_exists($cursor, $mounts)) {
                return true;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) break;
            $cursor = $parent;
        }

        // No está dentro de ningún mount conocido: probablemente local. stat() es seguro.
        return @is_dir($normalized);
    }

    private function scanSafely(string $absolute): ?array
    {
        $entries = @scandir($absolute);
        return $entries === false ? null : $entries;
    }

    /**
     * scandir con diagnóstico: distingue ENOENT (no existe) de EIO (legible
     * parcialmente o driver reportando error). Usado cuando isPathAccessible
     * devuelve false para dar mensaje correcto al usuario.
     */
    public function probePathError(string $absolute): string
    {
        $normalized = rtrim($absolute, '/');

        clearstatcache(true, $normalized);
        error_clear_last();

        $entries = @scandir($normalized);
        if ($entries !== false) {
            return 'path_missing';
        }

        $err = error_get_last();
        $msg = strtolower($err['message'] ?? '');

        if (str_contains($msg, 'no such file') || str_contains($msg, 'not found') || str_contains($msg, 'enoent')) {
            return 'path_missing';
        }
        if (str_contains($msg, 'input/output') || str_contains($msg, 'eio') || str_contains($msg, 'i/o error')) {
            return 'io_error';
        }
        if (str_contains($msg, 'permission') || str_contains($msg, 'eacces')) {
            return 'permission_denied';
        }

        // Default conservador: io_error (es lo más probable en NFS degradado)
        return 'io_error';
    }

    private function guessMime(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'mp4' => 'video/mp4', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav', 'mkv' => 'video/x-matroska', 'mov' => 'video/quicktime',
            'txt' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json',
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    private function emptyResponse(string $error): array
    {
        return [
            'files' => [],
            'pagination' => ['page' => 1, 'per_page' => 0, 'total' => 0, 'has_more' => false],
            'breadcrumbs' => [],
            'meta' => ['fs_primary' => true, 'error' => $error],
            'error' => $error,
        ];
    }

    private function emptyResponseWithBreadcrumbs(int $storageId, ?string $subPath, string $error): array
    {
        $resp = $this->emptyResponse($error);
        $resp['breadcrumbs'] = $this->parentChain($storageId, $subPath);
        return $resp;
    }
}
