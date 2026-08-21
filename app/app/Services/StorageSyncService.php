<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StorageSyncService
{
    /** Segundos que el camino manual espera por el lock antes de rendirse. */
    private const MANUAL_LOCK_WAIT = 3;

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
        return $this->syncFolderWithReport($storage, $parentId, $userId, $forcePrune)['files'];
    }

    /**
     * Igual que syncFolder(), pero ademas cuenta que hizo.
     *
     * Existe porque el listado devuelto no distingue "escanee y no habia nada
     * que cambiar" de "no llegue a escanear": el boton Actualizar mostraba el
     * mismo mensaje de exito en ambos casos. `status` nombra cual de las salidas
     * se tomo, para que la UI pueda decir la verdad.
     *
     * @return array{files: list<array<string,mixed>>, stats: array<string,mixed>}
     */
    public function syncFolderWithReport(StorageProvider $storage, ?int $parentId = null, ?int $userId = null, bool $forcePrune = false): array
    {
        if (!config('storage_sync.enabled', true)) {
            return $this->report($this->currentListing($storage->id, $parentId), 'sync_disabled');
        }

        $lock = Cache::lock(
            "sync:folder:{$storage->id}:" . ($parentId ?? 'root'),
            (int) config('storage_sync.lock.folder_ttl', 300)
        );

        if ($forcePrune) {
            // El camino forzado nace de un clic humano sobre una ruta concreta.
            // Ahi si conviene esperar: el cron corre cada 15 minutos y silentSync
            // se dispara en cada navegacion, asi que rendirse al instante convertia
            // el boton en un no-op justo cuando el usuario mira la pantalla.
            try {
                $lock->block(self::MANUAL_LOCK_WAIT);
            } catch (LockTimeoutException) {
                return $this->report($this->currentListing($storage->id, $parentId), 'locked');
            }
        } elseif (!$lock->get()) {
            // No bloqueante a proposito: si otro proceso ya esta escaneando esta
            // carpeta, devolvemos el listado actual en vez de esperar y repetir el
            // trabajo. N peticiones concurrentes => 1 escaneo.
            return $this->report($this->currentListing($storage->id, $parentId), 'locked');
        }

        try {
            return $this->doSyncFolder($storage, $parentId, $userId, $forcePrune);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @return array{files: list<array<string,mixed>>, stats: array<string,mixed>}
     */
    private function report(array $files, string $status, array $stats = []): array
    {
        return [
            'files' => $files,
            'stats' => $stats + [
                'status' => $status,
                'created' => 0,
                'updated' => 0,
                'deleted' => 0,
                'disk_count' => 0,
                'pruned' => false,
                'reason' => null,
            ],
        ];
    }

    private function doSyncFolder(StorageProvider $storage, ?int $parentId, ?int $userId, bool $forcePrune): array
    {
        $basePath = $storage->base_path;
        $parentFolder = null;
        $scanPath = $basePath;

        if ($parentId !== null) {
            $parentFolder = File::find($parentId);
            if (!$parentFolder || $parentFolder->storage_provider_id !== $storage->id) {
                return $this->report([], 'unknown_folder');
            }
            $scanPath = rtrim($basePath, '/') . '/' . ltrim($parentFolder->path, '/');
        }

        $realPath = realpath($scanPath);
        if (!$realPath || !is_dir($realPath)) {
            return $this->report($this->currentListing($storage->id, $parentId), 'path_missing');
        }

        if (!$this->scanner->isPathWithinBase($basePath, $realPath)) {
            return $this->report([], 'path_outside_base');
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

            return $this->report(
                $this->currentListing($storage->id, $parentId),
                'mount_detached',
                ['reason' => $detached]
            );
        }

        $scan = $this->scanner->scanDirectory($realPath);

        // Escaneo inservible: ni se crea ni se borra nada. Devolver lo que hay en
        // BD es lo correcto — el disco no dijo nada creible sobre este estado.
        if (!$scan->usable()) {
            Log::warning('storage_sync.scan_untrusted', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
            ] + $scan->context());
            $this->markInaccessible($storage);

            return $this->report(
                $this->currentListing($storage->id, $parentId),
                'scan_untrusted',
                ['reason' => $scan->failureReason]
            );
        }

        // Escaneo parcial: se leyo el directorio pero alguna entrada concreta dio
        // error. Se sigue adelante con las que si se leyeron — antes se descartaba
        // la carpeta entera y con ella los archivos sanos. NO se llama a
        // markInaccessible(): un archivo roto de junio no puede marcar como caido
        // un storage que responde perfectamente.
        if ($scan->isPartial()) {
            $this->logUnreadableEntries($storage, $parentId, $scan);
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

        // Total de la carpeta ANTES de ir descontando los emparejados. Es lo que
        // PruneGuard necesita para su regla de proporcion: mas abajo $bdFiles ya
        // solo contiene huerfanos, y pasarle ese conteo hacia que el ratio midiera
        // huerfanos-menos-disco sobre huerfanos, que no significa nada.
        $totalDbRows = $bdFiles->count();

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
        //
        // scanOk sale del escaneo y ya no es un `true` fijo: arriba solo se
        // descarto lo inservible, y un escaneo parcial llega hasta aqui. Con
        // $scan->ok en false, PruneGuard rechaza por 'scan_untrusted' sin
        // necesitar logica propia — lo que no se pudo leer no puede contarse como
        // desaparecido del disco. Ni siquiera $forcePrune levanta ese rechazo.
        $orphanCount = $bdFiles->count();

        $decision = $this->pruneGuard->decide(
            dbCount: $totalDbRows,
            diskCount: count($realEntries),
            scanOk: $scan->ok,
            forced: $forcePrune,
        );

        if ($decision->refused()) {
            Log::warning('storage_sync.prune_refused', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
                'path' => $parentFolder?->path ?? '/',
                'db_count' => $totalDbRows,
                'disk_count' => count($realEntries),
                'orphans' => $orphanCount,
                'reason' => $decision->reason,
            ] + $decision->context);
        } else {
            foreach ($bdFiles as $orphanFile) {
                if ($orphanFile->is_folder) {
                    // Cuenta el subarbol entero: borrar una carpeta-dia de prensa
                    // se lleva cientos de filas, y decir "1 eliminado" en la UI
                    // seria enganoso.
                    $deleted += $this->deleteRecursively($orphanFile->id);
                } else {
                    $orphanFile->delete();
                    $deleted++;
                }
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

        return $this->report($this->currentListing($storage->id, $parentId), 'synced', [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'disk_count' => count($realEntries),
            'orphans' => $orphanCount,
            'pruned' => !$decision->refused(),
            'reason' => $decision->refused() ? $decision->reason : null,
            'partial' => $scan->isPartial(),
        ]);
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

    /**
     * Avisa de las entradas ilegibles UNA vez por carpeta y ventana.
     *
     * Sin throttle esto genera un warning por carpeta en cada pasada del
     * sincronizador: 30 carpetas dañadas producian ~1476 lineas al dia repitiendo
     * exactamente la misma informacion. El evento es distinto de
     * 'scan_untrusted', que debe seguir significando "no me fio de nada de este
     * directorio".
     */
    private function logUnreadableEntries(StorageProvider $storage, ?int $parentId, ScanResult $scan): void
    {
        $key = "storage_sync:unreadable:{$storage->id}:" . ($parentId ?? 'root');

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, (int) config('storage_sync.unreadable_log_ttl', 21600));

        Log::warning('storage_sync.entries_unreadable', [
            'storage_id' => $storage->id,
            'parent_id' => $parentId,
            'hint' => 'entradas ilegibles en disco (EIO/stat fallido); el resto de la carpeta si se sincronizo',
        ] + $scan->context());
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
            $rootReport = $this->syncFolderWithReport($storage, null, $userId, $forcePrune);
            $stats['created'] += $rootReport['stats']['created'];
            $stats['updated'] += $rootReport['stats']['updated'];
            $stats['deleted'] += $rootReport['stats']['deleted'];

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

                        // Contadores reales del sync. Antes se contaban las entradas
                        // devueltas sin 'id', que siempre era cero: el listado sale
                        // de la BD y todas las filas tienen id.
                        $report = $this->syncFolderWithReport($storage, $folder->id, $userId, $forcePrune);
                        $stats['created'] += $report['stats']['created'];
                        $stats['updated'] += $report['stats']['updated'];
                        $stats['deleted'] += $report['stats']['deleted'];
                    }
                });

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /** @return int filas borradas, contando el subarbol completo */
    private function deleteRecursively(int $fileId): int
    {
        $count = 0;
        $children = File::where('parent_id', $fileId)->get();
        foreach ($children as $child) {
            if ($child->is_folder) {
                $count += $this->deleteRecursively($child->id);
            } else {
                $child->delete();
                $count++;
            }
        }
        File::destroy($fileId);

        return $count + 1;
    }
}
