<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StorageSyncService
{
    public function __construct(
        private FileScannerService $scanner,
        private FileRegistry $registry,
        private PruneGuard $pruneGuard,
        private MountGuard $mountGuard,
    ) {}

    /**
     * Sincroniza una carpeta contra el disco.
     *
     * Serializada con un lock por (storage, carpeta): antes, N cargas de pagina
     * concurrentes sobre una carpeta vacia lanzaban N escaneos simultaneos que se
     * pisaban e insertaban duplicados. El lock vive AQUI y no en los llamadores
     * para que los cinco puntos de entrada lo hereden y ninguno pueda olvidarlo.
     */
    public function syncFolder(StorageProvider $storage, ?int $parentId = null, ?int $userId = null, bool $forcePrune = false): array
    {
        if (!config('storage_sync.enabled', true)) {
            return $this->currentListing($storage->id, $parentId);
        }

        $lock = Cache::lock(
            "sync:folder:{$storage->id}:" . ($parentId ?? 'root'),
            (int) config('storage_sync.lock.folder_ttl', 300)
        );

        // No bloqueante a proposito: si otro proceso ya esta escaneando esta
        // carpeta, devolvemos el listado actual en vez de esperar y repetir el
        // trabajo. N peticiones concurrentes => 1 escaneo.
        if (!$lock->get()) {
            return $this->currentListing($storage->id, $parentId);
        }

        try {
            return $this->doSyncFolder($storage, $parentId, $userId, $forcePrune);
        } finally {
            $lock->release();
        }
    }

    private function doSyncFolder(StorageProvider $storage, ?int $parentId, ?int $userId, bool $forcePrune): array
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
            return $this->currentListing($storage->id, $parentId);
        }

        if (!$this->scanner->isPathWithinBase($basePath, $realPath)) {
            return [];
        }

        // Un montaje de red caido deja el punto de montaje como directorio local
        // vacio y legible: indistinguible de una carpeta vacia sin esta guarda.
        if ($detached = $this->mountGuard->detachedAncestor($realPath)) {
            Log::warning('storage_sync.mount_detached', [
                'storage_id' => $storage->id,
                'path' => $realPath,
                'mount_point' => $detached,
            ]);
            $this->markInaccessible($storage);

            return $this->currentListing($storage->id, $parentId);
        }

        $scan = $this->scanner->scanDirectory($realPath);

        // Escaneo no fiable: ni se crea ni se borra nada. Devolver lo que hay en
        // BD es lo correcto — el disco no dijo nada creible sobre este estado.
        if (!$scan->ok) {
            Log::warning('storage_sync.scan_untrusted', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
            ] + $scan->context());
            $this->markInaccessible($storage);

            return $this->currentListing($storage->id, $parentId);
        }

        $realEntries = $scan->entries;

        // groupBy y no keyBy: keyBy COLAPSABA silenciosamente los duplicados
        // existentes, y por eso la purga de huerfanos no podia verlos ni
        // limpiarlos nunca. Aqui se toma el primero como canonico y los extras se
        // registran — borrarlos corresponde a `files:dedupe`, que re-parenta
        // antes (la FK parent_id es ON DELETE CASCADE).
        $grouped = File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->get()
            ->groupBy('path');

        $bdFiles = collect();
        $duplicatesSeen = 0;
        foreach ($grouped as $path => $rows) {
            $bdFiles[$path] = $rows->first();
            if ($rows->count() > 1) {
                $duplicatesSeen += $rows->count() - 1;
            }
        }

        if ($duplicatesSeen > 0) {
            Log::warning('storage_sync.duplicate_rows', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
                'extra_rows' => $duplicatesSeen,
                'hint' => 'ejecutar php artisan files:dedupe',
            ]);
        }

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

        // --- Purga de huerfanos, bajo guarda ---
        //
        // Aqui es donde el 2026-07-27 se borro el arbol completo: el escaneo de
        // un NFS caido devolvio 0 entradas y todo lo que habia en BD quedo
        // marcado como huerfano.
        $decision = $this->pruneGuard->decide(
            dbCount: $bdFiles->count(),
            diskCount: count($realEntries),
            scanOk: true,          // ya se descarto arriba el escaneo no fiable
            forced: $forcePrune,
        );

        if ($decision->refused()) {
            Log::warning('storage_sync.prune_refused', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
                'path' => $parentFolder?->path ?? '/',
                'db_count' => $bdFiles->count(),
                'disk_count' => count($realEntries),
                'reason' => $decision->reason,
            ] + $decision->context);
        } else {
            foreach ($bdFiles as $orphanFile) {
                if ($orphanFile->is_folder) {
                    $this->deleteRecursively($orphanFile->id);
                } else {
                    $orphanFile->delete();
                }
                $deleted++;
            }
        }

        if ($parentId !== null && $parentFolder) {
            // store directory mtime so fullSync can skip it next time when nothing changed
            $dirMtime = \Carbon\Carbon::createFromTimestamp(filemtime($realPath));
            $parentFolder->update(['file_modified_at' => $dirMtime]);
        }

        if ($created > 0 || $deleted > 0) {
            $this->invalidateFolderCache($storage->id, $parentId);
        }

        return $this->currentListing($storage->id, $parentId);
    }

    /**
     * Listado actual desde BD, sin tocar disco.
     *
     * Es lo que se devuelve cuando no se puede o no se debe escanear (sync
     * desactivado, lock ocupado, escaneo no fiable, montaje caido): el usuario ve
     * lo ultimo conocido en vez de una lista vacia enganosa.
     */
    private function currentListing(int $storageId, ?int $parentId): array
    {
        return File::where('storage_provider_id', $storageId)
            ->where('parent_id', $parentId)
            ->orderBy('is_folder', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    private function markInaccessible(StorageProvider $storage): void
    {
        // Que la condicion se vea en la UI en vez de absorberse en silencio.
        if ($storage->is_accessible !== false) {
            $storage->forceFill(['is_accessible' => false])->saveQuietly();
        }
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

        // Via FileRegistry: si otro proceso gana la carrera, se lee al ganador en
        // vez de insertar una copia. Antes era un File::create() pelado.
        return $this->registry->ensure($storage, $path, [
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

    /**
     * Sincroniza el storage completo.
     *
     * Bloqueado por storage. Jerarquia estricta storage -> carpeta, asi que no
     * hay deadlock posible con el lock de syncFolder().
     */
    public function fullSync(StorageProvider $storage, int $userId, bool $force = false, bool $forcePrune = false): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'duplicate_folders_skipped' => 0,
        ];

        if (!config('storage_sync.enabled', true)) {
            $stats['disabled'] = true;

            return $stats;
        }

        $lock = Cache::lock(
            "sync:storage:{$storage->id}",
            (int) config('storage_sync.lock.storage_ttl', 3600)
        );

        if (!$lock->get()) {
            $stats['skipped_locked'] = true;

            return $stats;
        }

        try {
            $this->syncRootFolder($storage, $userId);

            // $seenPaths evita el amplificador: fullSync recorria TODAS las filas
            // de carpeta, duplicados incluidos. Como cada copia resuelve al mismo
            // directorio fisico y no encuentra nada bajo su propio parent_id,
            // recreaba el subarbol entero bajo cada una — de ahi las 36 copias.
            $seenPaths = [];

            // chunkById en vez de get(): 23k filas de carpeta en memoria de golpe
            // es un problema por si mismo.
            File::where('storage_provider_id', $storage->id)
                ->where('is_folder', true)
                ->orderBy('id')
                ->chunkById(500, function ($folders) use ($storage, $userId, $force, $forcePrune, &$stats, &$seenPaths) {
                    foreach ($folders as $folder) {
                        if (isset($seenPaths[$folder->path])) {
                            $stats['duplicate_folders_skipped']++;
                            continue;
                        }
                        $seenPaths[$folder->path] = true;

                        $realPath = realpath(rtrim($storage->base_path, '/') . '/' . ltrim($folder->path, '/'));

                        if (!$realPath || !is_dir($realPath)) {
                            $this->syncFolder($storage, $folder->id, $userId, $forcePrune);
                            continue;
                        }

                        // skip si el directorio no cambio desde el ultimo sync (solo si no es force)
                        if (!$force && $folder->file_modified_at !== null && filemtime($realPath) <= $folder->file_modified_at->timestamp) {
                            $stats['skipped']++;
                            continue;
                        }

                        $folderEntries = $this->syncFolder($storage, $folder->id, $userId, $forcePrune);
                        $stats['created'] += count(array_filter($folderEntries, fn ($e) => !isset($e['id'])));
                    }
                });

            return $stats;
        } finally {
            $lock->release();
        }
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
