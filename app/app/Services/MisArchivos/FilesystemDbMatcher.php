<?php

namespace App\Services\MisArchivos;

use App\Models\StorageProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FilesystemDbMatcher
{
    public function __construct() {}

    /**
     * @return array{scanned:int, created:int, updated:int, missing_marked:int, missing_purged:int, skipped:int}
     */
    public function matchStorage(int $storageId, string $mode = 'hot_warm'): array
    {
        $stats = [
            'scanned' => 0,
            'created' => 0,
            'updated' => 0,
            'missing_marked' => 0,
            'missing_purged' => 0,
            'skipped' => 0,
        ];

        $storage = StorageProvider::find($storageId);
        if (!$storage || $storage->type !== 'local') {
            $stats['skipped']++;
            return $stats;
        }

        if (in_array($mode, ['hot_only', 'hot_warm', 'hot_warm_cold'], true)) {
            $this->matchHot($storage, $stats);
        }

        if (in_array($mode, ['hot_warm', 'hot_warm_cold'], true)) {
            $depth = (int) config('mis_archivos.matcher_warm_depth', 3);
            $this->matchWarm($storage, $depth, $stats);
        }

        if ($mode === 'hot_warm_cold') {
            $this->matchCold($storage, $stats);
        }

        Cache::put('mis_archivos:matcher_last_run', CarbonImmutable::now()->toIso8601String(), 86400);

        Log::info('mis_archivos.matcher_run', $stats + ['storage_id' => $storageId, 'mode' => $mode]);
        return $stats;
    }

    private function matchHot(StorageProvider $storage, array &$stats): void
    {
        $recent = Cache::get("mis_archivos:recent_paths:{$storage->id}", []);
        if (!is_array($recent) || empty($recent)) {
            return;
        }
        $existingMap = $this->loadExistingPathMap($storage->id, $recent);
        foreach ($recent as $relPath) {
            $this->matchOneFromMap($storage, (string) $relPath, $existingMap, $stats);
        }
    }

    private function matchWarm(StorageProvider $storage, int $depth, array &$stats): void
    {
        $discovered = [];
        $this->collectFromFilesystem($storage, '', 0, $depth, $discovered);

        $existingMap = $this->loadExistingPathMap($storage->id, array_keys($discovered));

        $toInsert = [];
        $toUpdate = [];
        $now = CarbonImmutable::now();

        foreach ($discovered as $relPath => $info) {
            $existingId = $existingMap[$relPath] ?? null;

            if ($existingId) {
                $toUpdate[] = [
                    'id' => (int) $existingId,
                    'size' => $info['is_folder'] ? 0 : (int) ($info['size'] ?? 0),
                    'is_folder' => $info['is_folder'],
                    'file_modified_at' => $info['mtime'],
                    'pending_deletion_at' => null,
                    'updated_at' => $now,
                ];
            } else {
                $toInsert[] = [
                    'name' => $info['name'],
                    'path' => $relPath,
                    'storage_provider_id' => $storage->id,
                    'owner_id' => $this->resolveOwnerId($storage),
                    'parent_id' => null,
                    'is_folder' => $info['is_folder'],
                    'size' => $info['is_folder'] ? 0 : (int) ($info['size'] ?? 0),
                    'mime_type' => $info['is_folder'] ? 'folder' : $this->guessMime($info['name']),
                    'pending_deletion_at' => null,
                    'file_modified_at' => $info['mtime'],
                    'is_personal' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->bulkInsert($toInsert, $storage, $stats);
        $this->bulkUpdate($toUpdate, $stats);

        $stats['scanned'] += count($discovered);

        foreach (array_keys($discovered) as $relPath) {
            if (!isset($existingMap[$relPath])) {
                Cache::forget("mis_archivos:path_lookup:" . $storage->id . ":" . md5($relPath));
            }
        }
    }

    private function collectFromFilesystem(
        StorageProvider $storage,
        string $relPath,
        int $level,
        int $maxDepth,
        array &$discovered,
    ): void {
        if ($level > $maxDepth) return;

        $abs = rtrim($storage->base_path, '/') . ($relPath === '' ? '' : '/' . $relPath);

        $mountGuard = app(\App\Services\MountGuard::class);
        if (($detached = $mountGuard->detachedAncestor($abs)) !== null) {
            Log::warning('mis_archivos.matcher_mount_detached', [
                'storage_id' => $storage->id,
                'mount_point' => $detached,
            ]);
            return;
        }

        $entries = @scandir($abs);
        if ($entries === false) return;

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            if (str_starts_with($name, '.')) continue;
            if ($name === 'node_modules' || $name === '.git') continue;

            $childAbs = $abs . '/' . $name;
            $childRel = $relPath === '' ? $name : ($relPath . '/' . $name);

            $stat = @stat($childAbs);
            if ($stat === false) continue;

            $isFolder = ($stat['mode'] & 040000) === 040000;

            // En warm solo registramos folders: los archivos (m4a etc.) los cubre
            // el modo cold via chunkById sobre files existentes. Mantener la lista
            // en folders-only baja 10-100x el tiempo de walk para emisoras con miles
            // de grabaciones por carpeta-dia.
            if (!$isFolder) continue;

            $discovered[$childRel] = [
                'name' => $name,
                'is_folder' => true,
                'size' => 0,
                'mtime' => CarbonImmutable::createFromTimestamp($stat['mtime'] ?? time()),
            ];

            if ($level < $maxDepth) {
                $this->collectFromFilesystem($storage, $childRel, $level + 1, $maxDepth, $discovered);
            }
        }
    }

    private function matchCold(StorageProvider $storage, array &$stats): void
    {
        $now = CarbonImmutable::now();
        $graceDays = (int) config('mis_archivos.missing_grace_days', 7);

        DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->whereNull('pending_deletion_at')
            ->where('is_folder', true)
            ->chunkById(500, function ($rows) use ($storage, &$stats) {
                if ($rows->isEmpty()) return;
                $paths = $rows->pluck('path')->all();
                $existingMap = $this->loadExistingPathMap($storage->id, $paths);

                $toUpdate = [];
                $now = CarbonImmutable::now();
                foreach ($rows as $row) {
                    $info = $this->checkOnDisk($storage, $row->path);
                    if ($info === null) {
                        $marked = DB::table('files')
                            ->where('id', $row->id)
                            ->whereNull('pending_deletion_at')
                            ->update(['pending_deletion_at' => $now]);
                        if ($marked > 0) {
                            $stats['missing_marked']++;
                        }
                    } else {
                        $toUpdate[] = [
                            'id' => (int) $row->id,
                            'size' => $info['is_folder'] ? 0 : (int) ($info['size'] ?? 0),
                            'is_folder' => $info['is_folder'],
                            'file_modified_at' => $info['mtime'],
                            'pending_deletion_at' => null,
                            'updated_at' => $now,
                        ];
                    }
                }
                $this->bulkUpdate($toUpdate, $stats);
                $stats['scanned'] += count($rows);
            }, 'id');

        $purgeCutoff = $now->subDays($graceDays);
        $purgeCount = DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->whereNotNull('pending_deletion_at')
            ->where('pending_deletion_at', '<', $purgeCutoff)
            ->delete();
        $stats['missing_purged'] += (int) $purgeCount;
    }

    private function matchOneFromMap(
        StorageProvider $storage,
        string $relPath,
        array $existingMap,
        array &$stats,
    ): void {
        $info = $this->checkOnDisk($storage, $relPath);
        if ($info === null) {
            $existingId = $existingMap[$relPath] ?? null;
            if ($existingId) {
                $marked = DB::table('files')
                    ->where('id', $existingId)
                    ->whereNull('pending_deletion_at')
                    ->update(['pending_deletion_at' => CarbonImmutable::now()]);
                if ($marked > 0) {
                    $stats['missing_marked']++;
                }
            }
            return;
        }

        $existingId = $existingMap[$relPath] ?? null;
        $now = CarbonImmutable::now();
        if ($existingId) {
            $this->bulkUpdate([[
                'id' => (int) $existingId,
                'size' => $info['is_folder'] ? 0 : (int) ($info['size'] ?? 0),
                'is_folder' => $info['is_folder'],
                'file_modified_at' => $info['mtime'],
                'pending_deletion_at' => null,
                'updated_at' => $now,
            ]], $stats);
        } else {
            $this->bulkInsert([[
                'name' => $info['name'],
                'path' => $relPath,
                'storage_provider_id' => $storage->id,
                'owner_id' => $this->resolveOwnerId($storage),
                'parent_id' => null,
                'is_folder' => $info['is_folder'],
                'size' => $info['is_folder'] ? 0 : (int) ($info['size'] ?? 0),
                'mime_type' => $info['is_folder'] ? 'folder' : $this->guessMime($info['name']),
                'pending_deletion_at' => null,
                'file_modified_at' => $info['mtime'],
                'is_personal' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]], $storage, $stats);
            Cache::forget("mis_archivos:path_lookup:" . $storage->id . ":" . md5($relPath));
        }
        $stats['scanned']++;
    }

    /** @return array<string,int> map de path => id */
    private function loadExistingPathMap(int $storageId, array $paths): array
    {
        if (empty($paths)) return [];

        $chunks = array_chunk($paths, 500);
        $map = [];
        foreach ($chunks as $chunk) {
            $rows = DB::table('files')
                ->where('storage_provider_id', $storageId)
                ->whereIn('path', $chunk)
                ->select(['id', 'path'])
                ->get();
            foreach ($rows as $row) {
                $map[$row->path] = (int) $row->id;
            }
        }
        return $map;
    }

    private function checkOnDisk(StorageProvider $storage, string $relPath): ?array
    {
        $absPath = rtrim($storage->base_path, '/') . '/' . ltrim($relPath, '/');

        // CRITICO: stat() sobre NFS hard puede bloquear. Verificamos primero
        // que el mount siga reportado por /proc/self/mounts (lectura local).
        $mountGuard = app(\App\Services\MountGuard::class);
        if (($detached = $mountGuard->detachedAncestor($absPath)) !== null) {
            Log::warning('mis_archivos.matcher_mount_detached', [
                'storage_id' => $storage->id,
                'mount_point' => $detached,
            ]);
            return null;
        }

        // isMounted() verifica /proc/self/mounts primero (rapido, local).
        // Solo hace stat() si el path NO está en mounts.
        if (!$mountGuard->isMounted($absPath) && !@is_dir($absPath)) {
            return null;
        }

        $stat = @stat($absPath);
        if ($stat === false) return null;

        $isFolder = ($stat['mode'] & 040000) === 040000;
        return [
            'name' => basename($relPath),
            'is_folder' => $isFolder,
            'size' => $stat['size'] ?? 0,
            'mtime' => CarbonImmutable::createFromTimestamp($stat['mtime'] ?? time()),
        ];
    }

    private function bulkInsert(array $rows, StorageProvider $storage, array &$stats): void
    {
        if (empty($rows)) return;
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('files')->insert($chunk);
        }
        $stats['created'] += count($rows);
    }

    private function bulkUpdate(array $rows, array &$stats): void
    {
        if (empty($rows)) return;
        $now = CarbonImmutable::now();
        foreach ($rows as $row) {
            DB::table('files')->where('id', $row['id'])->update([
                'size' => $row['size'],
                'is_folder' => $row['is_folder'],
                'file_modified_at' => $row['file_modified_at'],
                'pending_deletion_at' => $row['pending_deletion_at'],
                'updated_at' => $row['updated_at'] ?? $now,
            ]);
        }
        $stats['updated'] += count($rows);
    }

    /**
     * Eager sync: asegura que TODAS las entries de una carpeta listada
     * existan en BD. Llamado por FilesystemListingService::list() justo
     * después del scandir() para que cada file tenga file_id y se pueda
     * ver/editar/compartir sin lazy-create ni endpoints especiales.
     *
     * Devuelve map `path => id` para que el caller pueda popular
     * `file.id` en cada entry antes de serializar al cliente.
     *
     * @param array<int, array{name:string, path:string, is_folder:bool, stat:array}> $entries
     * @return array<string, int>
     */
    public function ensureFolderSync(StorageProvider $storage, array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $paths = array_column($entries, 'path');
        $existingMap = $this->loadExistingPathMap($storage->id, $paths);

        $now = CarbonImmutable::now();
        $ownerId = $this->resolveOwnerId($storage);
        $toInsert = [];
        $toUpdate = [];

        foreach ($entries as $entry) {
            $relPath = $entry['path'];
            $isFolder = $entry['is_folder'];
            $stat = $entry['stat'];

            if (isset($existingMap[$relPath])) {
                $toUpdate[] = [
                    'id' => (int) $existingMap[$relPath],
                    'size' => $isFolder ? 0 : (int) ($stat['size'] ?? 0),
                    'is_folder' => $isFolder,
                    'file_modified_at' => CarbonImmutable::createFromTimestamp($stat['mtime'] ?? time()),
                    'pending_deletion_at' => null,
                    'updated_at' => $now,
                ];
            } else {
                $toInsert[] = [
                    'name' => $entry['name'],
                    'path' => $relPath,
                    'storage_provider_id' => $storage->id,
                    'owner_id' => $ownerId,
                    'parent_id' => null,
                    'is_folder' => $isFolder,
                    'size' => $isFolder ? 0 : (int) ($stat['size'] ?? 0),
                    'mime_type' => $isFolder ? 'folder' : $this->guessMime($entry['name']),
                    'pending_deletion_at' => null,
                    'file_modified_at' => CarbonImmutable::createFromTimestamp($stat['mtime'] ?? time()),
                    'is_personal' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($toInsert)) {
            $stats = ['created' => 0];
            $this->bulkInsert($toInsert, $storage, $stats);
        }
        if (!empty($toUpdate)) {
            $stats = ['updated' => 0];
            $this->bulkUpdate($toUpdate, $stats);
        }

        $newMap = $this->loadExistingPathMap($storage->id, $paths);

        foreach ($paths as $p) {
            Cache::forget("mis_archivos:path_lookup:" . $storage->id . ":" . md5($p));
        }

        return $newMap;
    }

    private function resolveOwnerId(StorageProvider $storage): int
    {
        $full = $storage->userStorages()->where('permissions', 'full')->orderBy('user_storages.id')->first();
        if ($full) {
            return (int) $full->user_id;
        }
        $any = $storage->userStorages()->orderBy('user_storages.id')->first();
        return (int) ($any->user_id ?? 1);
    }

    private function guessMime(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'mp4' => 'video/mp4', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav', 'txt' => 'text/plain', 'json' => 'application/json',
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
