<?php

namespace App\Services\MisArchivos;

use App\Models\StorageProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FilesystemDbMatcher
{
    public function __construct(
        private FilesystemListingService $listingService,
    ) {}

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
        if (!is_array($recent)) return;

        foreach ($recent as $relPath) {
            $this->matchOne($storage, (string) $relPath, $stats);
        }
    }

    private function matchWarm(StorageProvider $storage, int $depth, array &$stats): void
    {
        $this->matchRecursive($storage, rtrim($storage->base_path, '/'), '', 0, $depth, $stats);
    }

    private function matchRecursive(StorageProvider $storage, string $baseAbs, string $relPath, int $level, int $maxDepth, array &$stats): void
    {
        if ($level > $maxDepth) return;

        $abs = $baseAbs . ($relPath === '' ? '' : '/' . $relPath);
        $entries = @scandir($abs) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            if (str_starts_with($name, '.')) continue;
            if ($name === 'node_modules' || $name === '.git') continue;

            $childRel = $relPath === '' ? $name : ($relPath . '/' . $name);
            $this->matchOne($storage, $childRel, $stats);

            if ($level < $maxDepth) {
                $childAbs = $abs . '/' . $name;
                if (is_dir($childAbs)) {
                    $this->matchRecursive($storage, $baseAbs, $childRel, $level + 1, $maxDepth, $stats);
                }
            }
        }
    }

    private function matchCold(StorageProvider $storage, array &$stats): void
    {
        $graceDays = (int) config('mis_archivos.missing_grace_days', 7);
        $now = CarbonImmutable::now();

        DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->whereNull('pending_deletion_at')
            ->where('is_folder', true)
            ->chunkById(500, function ($rows) use ($storage, &$stats) {
                foreach ($rows as $row) {
                    $this->matchOne($storage, $row->path, $stats);
                }
            }, 'id');

        $purgeCutoff = $now->subDays($graceDays);
        $purgeCount = DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->whereNotNull('pending_deletion_at')
            ->where('pending_deletion_at', '<', $purgeCutoff)
            ->delete();
        $stats['missing_purged'] += (int) $purgeCount;
    }

    private function matchOne(StorageProvider $storage, string $relPath, array &$stats): void
    {
        $absPath = rtrim($storage->base_path, '/') . '/' . ltrim($relPath, '/');
        $exists = file_exists($absPath);

        if (!$exists) {
            $marked = DB::table('files')
                ->where('storage_provider_id', $storage->id)
                ->where('path', $relPath)
                ->whereNull('pending_deletion_at')
                ->update(['pending_deletion_at' => CarbonImmutable::now()]);
            if ($marked > 0) {
                $stats['missing_marked']++;
            }
            return;
        }

        $stat = @stat($absPath);
        if ($stat === false) {
            return;
        }

        $isFolder = ($stat['mode'] & 040000) === 040000;
        $existingId = DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->where('path', $relPath)
            ->value('id');

        $name = basename($relPath);
        $payload = [
            'name' => $name,
            'size' => $isFolder ? 0 : (int) ($stat['size'] ?? 0),
            'is_folder' => $isFolder,
            'file_modified_at' => CarbonImmutable::createFromTimestamp($stat['mtime'] ?? time()),
            'pending_deletion_at' => null,
            'updated_at' => CarbonImmutable::now(),
        ];

        if ($existingId) {
            DB::table('files')->where('id', $existingId)->update($payload);
            $stats['updated']++;
        } else {
            $payload['path'] = $relPath;
            $payload['storage_provider_id'] = $storage->id;
            $payload['owner_id'] = $this->resolveOwnerId($storage);
            $payload['mime_type'] = $isFolder ? 'folder' : $this->guessMime($name);
            $payload['is_personal'] = false;
            $payload['parent_id'] = null;
            $payload['created_at'] = CarbonImmutable::now();
            DB::table('files')->insert($payload);
            $stats['created']++;
            $this->listingService->invalidateFileIdCache($storage->id, $relPath);
        }
        $stats['scanned']++;
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
