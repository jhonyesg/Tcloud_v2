<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;

class StorageSyncService
{
    private FileScannerService $scanner;

    public function __construct(FileScannerService $scanner)
    {
        $this->scanner = $scanner;
    }

    public function syncFolder(StorageProvider $storage, ?int $parentId = null, ?int $userId = null): array
    {
        $basePath = $storage->base_path;
        $parentFolder = null;
        $scanPath = $basePath;

        if ($parentId !== null) {
            $parentFolder = File::find($parentId);
            if (!$parentFolder || $parentFolder->storage_provider_id !== $storage->id) {
                return [];
            }
            $scanPath = rtrim($basePath, '/') . '/' . ltrim($parentFolder->path, '/');
        }

        $realPath = realpath($scanPath);
        if (!$realPath || !is_dir($realPath)) {
            return [];
        }

        if (!$this->scanner->isPathWithinBase($basePath, $realPath)) {
            return [];
        }

        $realEntries = $this->scanner->scanDirectory($realPath);

        $bdFiles = File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->get()
            ->keyBy('path');

        $realPaths = [];
        $created = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($realEntries as $entry) {
            $relativePath = $entry['is_folder'] 
                ? $entry['name'] 
                : $entry['name'];

            $fullRelativePath = $parentFolder 
                ? $parentFolder->path . '/' . $relativePath 
                : $relativePath;

            $realPaths[] = $fullRelativePath;

            if (isset($bdFiles[$fullRelativePath])) {
                $existingFile = $bdFiles[$fullRelativePath];
                $changes = [];
                if (!$existingFile->is_folder && $existingFile->size !== $entry['size']) {
                    $changes['size'] = $entry['size'];
                }
                if (isset($entry['modified_at'])) {
                    $entryModified = \Carbon\Carbon::createFromTimestamp($entry['modified_at']);
                    if (!$existingFile->file_modified_at || !$existingFile->file_modified_at->eq($entryModified)) {
                        $changes['file_modified_at'] = $entryModified;
                    }
                }
                if ($changes) {
                    $existingFile->update($changes);
                    $updated++;
                }
                unset($bdFiles[$fullRelativePath]);
            } else {
                $this->createFileFromScan($storage, $entry, $parentId, $userId);
                $created++;
            }
        }

        foreach ($bdFiles as $orphanFile) {
            if ($orphanFile->is_folder) {
                $this->deleteRecursively($orphanFile->id);
            } else {
                $orphanFile->delete();
            }
            $deleted++;
        }

        if ($parentId !== null && $parentFolder) {
            // store directory mtime so fullSync can skip it next time when nothing changed
            $dirMtime = \Carbon\Carbon::createFromTimestamp(filemtime($realPath));
            $parentFolder->update(['file_modified_at' => $dirMtime]);
        }

        if ($created > 0 || $deleted > 0) {
            $this->invalidateFolderCache($storage->id, $parentId);
        }

        return File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->orderBy('is_folder', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function invalidateFolderCache(int $storageId, ?int $parentId): void
    {
        $pid = $parentId ?? 'null';
        Cache::increment("folder_gen:{$storageId}:{$pid}");
    }

    public function syncRootFolder(StorageProvider $storage, int $userId): array
    {
        return $this->syncFolder($storage, null, $userId);
    }

    private function createFileFromScan(StorageProvider $storage, array $entry, ?int $parentId, ?int $userId): File
    {
        $name = $entry['name'];
        $path = $entry['is_folder'] ? $name : $name;
        $parentPath = '';

        if ($parentId !== null) {
            $parentFolder = File::find($parentId);
            if ($parentFolder) {
                $path = $parentFolder->path . '/' . $name;
                $parentPath = $parentFolder->path;
            }
        }

        $modifiedAt = isset($entry['modified_at']) ? \Carbon\Carbon::createFromTimestamp($entry['modified_at']) : null;

        return File::create([
            'name' => $name,
            'path' => $path,
            'size' => $entry['size'] ?? 0,
            'mime_type' => $entry['mime_type'] ?? ($entry['is_folder'] ? 'folder' : 'application/octet-stream'),
            'storage_provider_id' => $storage->id,
            'owner_id' => $storage->userStorages()->first()?->user_id ?? 1,
            'parent_id' => $parentId,
            'is_folder' => $entry['is_folder'],
            'is_personal' => false,
            'file_modified_at' => $modifiedAt,
        ]);
    }

    private function detectOrphans(StorageProvider $storage, int $parentId, array $realPaths): array
    {
        return File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->whereNotIn('path', $realPaths)
            ->get();
    }

    private function deleteOrphanRecords(array $orphans): int
    {
        $count = 0;
        foreach ($orphans as $orphan) {
            if ($orphan->is_folder) {
                $this->deleteRecursively($orphan->id);
            } else {
                $orphan->delete();
            }
            $count++;
        }
        return $count;
    }

    public function fullSync(StorageProvider $storage, int $userId, bool $force = false): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
        ];

        $this->syncRootFolder($storage, $userId);

        $foldersToSync = File::where('storage_provider_id', $storage->id)
            ->where('is_folder', true)
            ->whereNotNull('parent_id')
            ->get();

        foreach ($foldersToSync as $folder) {
            $realPath = realpath(rtrim($storage->base_path, '/') . '/' . ltrim($folder->path, '/'));

            if (!$realPath || !is_dir($realPath)) {
                $this->syncFolder($storage, $folder->id, $userId);
                continue;
            }

            // skip si el directorio no cambio desde el ultimo sync (solo si no es force)
            if (!$force && $folder->file_modified_at !== null && filemtime($realPath) <= $folder->file_modified_at->timestamp) {
                $stats['skipped']++;
                continue;
            }

            $folderEntries = $this->syncFolder($storage, $folder->id, $userId);
            $stats['created'] += count(array_filter($folderEntries, fn($e) => !isset($e['id'])));
        }

        return $stats;
    }

    private function deleteRecursively(int $fileId): void
    {
        $children = File::where('parent_id', $fileId)->get();
        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->deleteRecursively($child->id);
            } else {
                $child->delete();
            }
        }
        File::destroy($fileId);
    }
}
