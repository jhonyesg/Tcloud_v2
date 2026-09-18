<?php

namespace App\Services;

use App\Models\File;
use App\Models\StorageProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StorageSyncService
{
    /** Segundos que el camino manual espera por el lock antes de rendirse. */
    private const MANUAL_LOCK_WAIT = 3;

    /**
     * Cache por instancia de los storages ordenados por profundidad de
     * base_path. La llena `findMoreSpecificStorage()` en su primera llamada.
     *
     * @var \Illuminate\Support\Collection<int,StorageProvider>|null
     */
    private ?\Illuminate\Support\Collection $moreSpecificCache = null;

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
            $this->markFolderUnknown($storage, $parentId);
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
            $this->markFolderUnknown($storage, $parentId);

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
            $this->markFolderUnknown($storage, $parentId);

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
        // Papelera: excluimos filas trashadas del matching para que el sync
        // no las toque, no las actualice ni las considere candidatas a prune.
        // El indice parcial files_trash_sweep_idx + el WHERE is_trashed=false
        // aqui mantienen este escaneo O(no-trash) incluso con papelera llena.
        $grouped = File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->where('is_trashed', false)
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
        $migrated = 0;

        // Self-healing delegation (change `2026-09-17-self-healing-sync-permissions`).
        // Se ejecuta ANTES del loop principal para que los files en este folder que
        // deberian pertenecer a un sub-storage se migren antes de que el prune los
        // vea como "orphans" (que aparecen en disco pero no en el storage correcto).
        // Sin este orden, el prune borraria archivos legitimos que solo tienen mal
        // el `storage_provider_id`. El self-healing preserva FKs porque solo cambia
        // `storage_provider_id` y `parent_id`, NO `file_id`.
        $migrated = $this->selfHealDelegationLeak($storage->id, $parentId);
        if ($migrated > 0) {
            $this->invalidateFolderCache($storage->id, $parentId);
            // Re-leer bdFiles porque la migracion pudo mover files fuera de este folder.
            $grouped = File::where('storage_provider_id', $storage->id)
                ->where('parent_id', $parentId)
                ->where('is_trashed', false)
                ->get()
                ->groupBy('path');
            $bdFiles = collect();
            foreach ($grouped as $path => $rows) {
                $bdFiles[$path] = $rows->first();
                if ($rows->count() > 1) {
                    $duplicatesSeen += $rows->count() - 1;
                }
            }
            $totalDbRows = $bdFiles->count();
        }

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
                    // createFromTimestamp() SIN zona devuelve un Carbon en UTC
                    // (su default). Con la sesion PostgreSQL en America/Bogota
                    // (config database.connections.pgsql.timezone), el binding se
                    // formatea con `Y-m-d H:i:s` y PostgreSQL lo interpreta en la
                    // zona de la sesion: hay que entregarlo ya en la zona de la
                    // app o el instante queda corrido +5h. Ver fix 2026-09-16.
                    $entryModified = \Carbon\Carbon::createFromTimestamp($entry['modified_at'], config('app.timezone'));
                    if (!$existingFile->file_modified_at || !$existingFile->file_modified_at->eq($entryModified)) {
                        $changes['file_modified_at'] = $entryModified;
                    }
                }
                if ($existingFile->availability_state !== 'available') {
                    $changes['availability_state'] = 'available';
                }
                $changes['last_verified_at'] = now();
                $changes['missing_since_at'] = null;
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

        // Regla 5: cuentas FK antes de pasar el veredicto a PruneGuard. Esto se
        // hace ANTES del decision() para que el rechazo por orphan_linked sepa
        // cuantos vinculos hay. Cada consulta es un EXISTS acotado, no una
        // agregacion: no satura el server aunque haya 700k candidatos.
        $linkedCount = 0;
        foreach ($bdFiles as $orphan) {
            if ($this->isFileLinked($orphan->id)) {
                $linkedCount++;
            }
        }

        $decision = $this->pruneGuard->decide(
            dbCount: $totalDbRows,
            diskCount: count($realEntries),
            scanOk: $scan->ok,
            linkedCount: $linkedCount,
            forced: $forcePrune,
        );

        if ($decision->refused()) {
            if ($decision->reason === 'orphan_linked') {
                $this->markOrphansMissing($bdFiles, $storage->id, $parentId);
            } else {
                $this->markOrphansUnknown($bdFiles);
            }
            Log::warning('storage_sync.prune_refused', [
                'storage_id' => $storage->id,
                'parent_id' => $parentId,
                'path' => $parentFolder?->path ?? '/',
                'db_count' => $totalDbRows,
                'disk_count' => count($realEntries),
                'orphans' => $orphanCount,
                'orphans_linked' => $linkedCount,
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
            // (zona de la app: ver nota en el otro createFromTimestamp de este archivo)
            $dirMtime = \Carbon\Carbon::createFromTimestamp(filemtime($realPath), config('app.timezone'));
            $parentFolder->update(['file_modified_at' => $dirMtime]);
        }

        if ($created > 0 || $deleted > 0) {
            $this->invalidateFolderCache($storage->id, $parentId);
        }

        return $this->report($this->currentListing($storage->id, $parentId), 'synced', [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'migrated' => $migrated,
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
     *
     * Ademas incluye archivos que pertenezcan a un SUB-storage mas especifico
     * cuya base_path sea prefijo de la carpeta que el usuario esta viendo.
     * Sin esto, navegar Disco_B/television/Canal_Rcn/16092026 desde "00 Discos"
     * (storage padre) mostraba los archivos viejos pero no los nuevos: el sync
     * delega los archivos nuevos al sub-storage (Canal_Rcn) y la carpeta padre
     * quedaba desincronizada respecto al disco. Ver resolveListingTargets().
     *
     * Dedup por nombre: si la misma ruta fisica existe en padre e hijo
     * (estado legado, ver migration pendiente), gana la fila del storage mas
     * especifico (base_path mas largo). Asi el usuario no ve duplicados del
     * mismo archivo.
     */
    private function currentListing(int $storageId, ?int $parentId): array
    {
        $targets = $this->resolveListingTargets($storageId, $parentId);

        $rowsByStorage = [];
        foreach ($targets as $t) {
            // Defense in depth: aunque `resolveListingTargets` ya filtra por
            // `duplicate_of_storage_id`, esta consulta evita que un storage mergeado
            // aparezca en el listado si el cache de targets quedo stale (TTL
            // 60s hoy, 300s para folders de hoy, 86400s para folders
            // historicos). Cambio `storage-physical-path-normalization`.
            if (StorageProvider::where('id', $t['storage_id'])->whereNotNull('duplicate_of_storage_id')->exists()) {
                continue;
            }
            $rowsByStorage[$t['storage_id']] = File::where('storage_provider_id', $t['storage_id'])
                ->where('parent_id', $t['parent_id'])
                ->where('is_trashed', false)
                ->get();
        }

        // Orden de especificidad descendente: el sub-storage gana sobre el padre.
        // Asignamos peso = longitud de base_path; al desempate por nombre, gana
        // el de mayor peso.
        $weights = [];
        foreach (array_keys($rowsByStorage) as $sid) {
            $sp = StorageProvider::find($sid);
            $weights[$sid] = $sp ? strlen((string) $sp->base_path) : 0;
        }

        $merged = collect();
        foreach ($rowsByStorage as $sid => $rows) {
            foreach ($rows as $r) {
                $merged->push((object) ['weight' => $weights[$sid], 'row' => $r]);
            }
        }

        // Dedup por nombre: el de mayor peso gana.
        $byName = [];
        foreach ($merged as $entry) {
            $name = $entry->row->name;
            if (!isset($byName[$name]) || $entry->weight > $byName[$name]->weight) {
                $byName[$name] = $entry;
            }
        }

        return collect(array_values($byName))
            ->map(fn ($e) => (array) $e->row->toArray())
            ->sortBy([['is_folder', 'desc'], ['created_at', 'desc']])
            ->values()
            ->toArray();
    }

    /**
     * Resuelve los pares (storage_id, parent_id) cuyos archivos deben aparecer
     * cuando el usuario navega la carpeta ($storageId, $parentId).
     *
     * Caso normal: solo el par pedido.
     * Caso sub-storage: si existe un sub-storage mas especifico cuya base_path
     * es prefijo de la carpeta que el usuario ve, tambien se incluyen los
     * archivos del folder correspondiente en ese sub-storage (resuelto por
     * path, no por id, porque el id cambia entre storages).
     *
     * Esto es lo que arregla "le doy a Actualizar y Mis Archivos no muestra los
     * archivos nuevos": la delegation logic del sync es correcta (los archivos
     * son del sub-storage), pero el listado del padre debe mergearlos para que
     * la UI no quede desfasada respecto al disco.
     *
     * @return list<array{storage_id:int, parent_id:?int}>
     */
    public function resolveListingTargets(int $storageId, ?int $parentId): array
    {
        // Change `storage-physical-path-normalization` (2026-09-17):
        // si el storage navegado es un duplicate no mergeado todavia, log
        // warning para que el operador sepa que el listado podria estar
        // incompleto. La deduplicacion real entre duplicados se hace en
        // `currentListing` por peso (`strlen(base_path)`); la normalizacion
        // completa llega via `storages:merge-duplicates --apply`.
        if (StorageProvider::where('id', $storageId)->whereNotNull('duplicate_of_storage_id')->exists()) {
            \Illuminate\Support\Facades\Log::info('storage_sync.listing_merged_storage', [
                'storage_id' => $storageId,
                'parent_id' => $parentId,
                'hint' => 'storage mergeado en otro; contenido listado via currentListing dedup',
            ]);
        }

        $targets = [['storage_id' => $storageId, 'parent_id' => $parentId]];

        if ($parentId === null) {
            return $targets;
        }

        $parentFolder = File::find($parentId);
        if (!$parentFolder || $parentFolder->storage_provider_id !== $storageId) {
            return $targets;
        }

        $storage = StorageProvider::find($storageId);
        if (!$storage || empty($storage->base_path)) {
            return $targets;
        }

        $absolutePath = rtrim($storage->base_path, '/') . '/' . ltrim($parentFolder->path, '/');
        $sub = $this->findMoreSpecificStorage($absolutePath, $storageId);
        if ($sub === null) {
            return $targets;
        }

        $subBase = rtrim((string) $sub->base_path, '/');
        $relativeToSub = ltrim(substr($absolutePath, strlen($subBase)), '/');

        $subFolder = File::where('storage_provider_id', $sub->id)
            ->where('path', $relativeToSub)
            ->where('is_folder', true)
            ->first();

        if (!$subFolder) {
            return $targets;
        }

        $targets[] = ['storage_id' => $sub->id, 'parent_id' => $subFolder->id];

        return $targets;
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

        // Fix mal-parenting (causa raiz #1 del bug del Sin indexar):
        // Si el cliente esta navegando una carpeta del storage PADRE pero esa
        // carpeta pertenece a un SUB-storage mas especifico, NO crear el file
        // bajo el padre — delegar al sub-storage para que sea el dueno real.
        // Esto evita que el padre reclame archivos que logicamente son del hijo.
        //
        // Ademas resuelve el parent_id dentro del sub-storage: el archivo debe
        // colgar del folder correspondiente en el sub-storage (mismo path
        // relativo), no quedar huerfano en la raiz del sub-storage con
        // parent_id=NULL. Sin eso, "Mis Archivos" no muestra los archivos
        // nuevos aunque el sync reporte created>0 (ver resolveListingTargets
        // y el bug "le doy a Actualizar y no actualiza").
        if (!$entry['is_folder']) {
            $absolutePath = rtrim($storage->base_path, '/') . '/' . ltrim($path, '/');
            $moreSpecificStorage = $this->findMoreSpecificStorage($absolutePath, $storage->id);
            if ($moreSpecificStorage !== null) {
                Log::info('storage_sync.delegated_to_substorage', [
                    'parent_storage_id' => $storage->id,
                    'parent_name' => $storage->name,
                    'sub_storage_id' => $moreSpecificStorage->id,
                    'sub_name' => $moreSpecificStorage->name,
                    'path' => $path,
                    'absolute_path' => $absolutePath,
                ]);
                $subBase = rtrim((string) $moreSpecificStorage->base_path, '/');
                $relativeToSub = ltrim(substr($absolutePath, strlen($subBase)), '/');
                $subParentPath = trim(dirname($relativeToSub), '/.');

                // Fix bug `mis-archivos-substorage-orphan-repair` (2026-09-17):
                // antes, si el folder intermedio no existia en el sub-storage,
                // $subParentFolderId quedaba NULL y el archivo delegado quedaba
                // huerfano en la raiz del sub-storage. Eso rompe el listing:
                // resolveListingTargets() no encuentra el folder intermedio y
                // devuelve solo el target del storage padre, asi que la UI ve
                // la carpeta vacia aunque el archivo fisico este en disco.
                //
                // Ahora: si la cadena de folders no existe en el sub-storage,
                // crearla con el mismo FileRegistry::ensure() que se usa para
                // los archivos. ensure() es idempotente por (storage_id, path)
                // asi que es seguro bajo concurrencia.
                $subParentFolderId = null;
                if ($subParentPath !== '' && $subParentPath !== '.') {
                    $subParentFolderId = $this->ensureSubstorageFolderChain(
                        $moreSpecificStorage,
                        $subParentPath,
                        $userId,
                    );
                }

                return $this->registry->ensure($moreSpecificStorage, $relativeToSub, [
                    'name' => $name,
                    'path' => $relativeToSub,
                    'size' => $entry['size'] ?? 0,
                    'mime_type' => $entry['mime_type'] ?? 'application/octet-stream',
                    'storage_provider_id' => $moreSpecificStorage->id,
                    'owner_id' => $moreSpecificStorage->userStorages()->first()?->user_id ?? 1,
                    'parent_id' => $subParentFolderId,
                    'is_folder' => false,
                    'file_modified_at' => isset($entry['modified_at'])
                        ? \Carbon\Carbon::createFromTimestamp($entry['modified_at'], config('app.timezone'))
                        : null,
                    'availability_state' => 'available',
                    'last_verified_at' => now(),
                    'missing_since_at' => null,
                ]);
            }
        }

        // Papelera: si ya existe una fila trashada con este path, no creamos
        // una nueva — la fila trashada es la canonica hasta que se restaure
        // o se purgue. Esto evita que el sync "recree" items que el usuario
        // acaba de mover a papelera (bug original reportado 2026-09-06).
        $existingTrashed = File::where('storage_provider_id', $storage->id)
            ->where('path', $path)
            ->where('is_trashed', true)
            ->first();
        if ($existingTrashed) {
            Log::info('storage_sync.skipped_trashed_collision', [
                'storage_id' => $storage->id,
                'path' => $path,
                'trashed_file_id' => $existingTrashed->id,
                'parent_id' => $parentId,
            ]);
            return $existingTrashed;
        }

        // Zona de la app: con la sesion PG en America/Bogota, entregar un Carbon
        // en UTC desplazaria el instante +5h. Ver fix 2026-09-16.
        $modifiedAt = isset($entry['modified_at'])
            ? \Carbon\Carbon::createFromTimestamp($entry['modified_at'], config('app.timezone'))
            : null;

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
            'file_modified_at' => $modifiedAt,
            'availability_state' => 'available',
            'last_verified_at' => now(),
            'missing_since_at' => null,
        ]);
    }

    private function markFolderUnknown(StorageProvider $storage, ?int $parentId): void
    {
        if ($parentId !== null) {
            File::where('id', $parentId)
                ->where('storage_provider_id', $storage->id)
                ->update([
                    'availability_state' => 'unknown',
                    'last_verified_at' => null,
                    'missing_since_at' => null,
                ]);
        }

        File::where('storage_provider_id', $storage->id)
            ->where('parent_id', $parentId)
            ->where('availability_state', '!=', 'unknown')
            ->update([
                'availability_state' => 'unknown',
                'last_verified_at' => null,
                'missing_since_at' => null,
            ]);
    }

    /**
     * Busca un sub-storage mas especifico cuyo base_path sea prefijo del
     * absolutePath dado. Usado por createFileFromScan() para evitar que el
     * padre reclame archivos del hijo cuando el cliente navega carpetas
     * anidadas (ej. cliente en Mis Archivos abre Emisoras 01 / Atlantico /
     * Blu — esos archivos son de storage 91 Blu Barranquilla, no de 49).
     *
     * FRONTERA (change `transcriptor-physical-file-identity`, design.md D7):
     * la delegacion es GEOMETRICA. Antes filtraba por
     * `StorageProvider::transcriptionEnabled()`, lo que violaba la
     * independencia de los modulos: apagar `transcription_enabled` de un
     * storage hijo hacia que Mis Archivos dejara de delegarle archivos y el
     * padre se los quedara. Eso produjo cientos de miles de filas duplicadas
     * (los hijos 36/59/37/46 con tx=false nunca recibieron la delegacion).
     *
     * Ahora decide solo por PROFUNDIDAD de ruta: el sub-storage mas especifico
     * gana, transcriba o no. Con el CASCADE roto en `transcriptions.file_id`
     * (migracion 2026_09_16_200200), esta delegacion tampoco puede destruir
     * transcripciones.
     *
     * @return StorageProvider|null el sub-storage mas especifico encontrado, o null
     */
    private function findMoreSpecificStorage(string $absolutePath, int $excludeStorageId): ?StorageProvider
    {
        // Cache por instancia (antes era `static`, que sobrevivia entre
        // requests de un worker PHP-FPM y podia servir datos rancios tras
        // cambiar un base_path). El servicio se resuelve por request, asi que
        // esto acota la vida de la cache al trabajo actual.
        //
        // Change `storage-physical-path-normalization` (2026-09-17):
        // excluimos storages mergeados (`duplicate_of_storage_id IS NOT NULL`) para que
        // el sync no delegue archivos a un storage que esta marcado como
        // duplicado de otro. Sin esta exclusion, el cron seguiria creando
        // filas en el duplicate tras el merge, anulando el efecto del comando.
        if ($this->moreSpecificCache === null) {
            $this->moreSpecificCache = StorageProvider::query()
                ->whereNotNull('base_path')
                ->whereRaw("rtrim(base_path, '/') <> ''")
                ->whereNull('duplicate_of_storage_id')
                ->orderByRaw("LENGTH(rtrim(base_path, '/')) DESC")
                ->get(['id', 'name', 'base_path', 'parent_storage_id'])
                ->keyBy('id');
        }

        $absolutePath = rtrim($absolutePath, '/');
        $best = null;
        $bestLen = 0;
        foreach ($this->moreSpecificCache as $candidate) {
            if ($candidate->id === $excludeStorageId) continue;
            $base = rtrim((string) $candidate->base_path, '/');
            if ($base === '' || $base === $absolutePath) continue;
            if (str_starts_with($absolutePath . '/', $base . '/') && strlen($base) > $bestLen) {
                $best = $candidate;
                $bestLen = strlen($base);
            }
        }
        return $best;
    }

    /**
     * Garantiza que existe la cadena de folders que contiene $subPath en el
     * sub-storage, creando los segmentos faltantes. Usado por
     * createFileFromScan() al delegar un archivo a un sub-storage: si el
     * folder intermedio no existe, antes el archivo quedaba con parent_id=NULL
     * (huerfano en la raiz), lo que rompia el listado de Mis Archivos.
     *
     * Idempotente por (storage_id, path): si el folder ya existe, lo retorna;
     * si no, lo crea. Recorre el path segmento por segmento de la raiz hacia
     * abajo para que la creacion siempre respete la jerarquia.
     *
     * Devuelve el id del folder hoja (= el folder correspondiente a $subPath),
     * o NULL si $subPath esta vacio (la raiz del sub-storage).
     */
    private function ensureSubstorageFolderChain(StorageProvider $storage, string $subPath, ?int $userId): ?int
    {
        $segments = array_values(array_filter(explode('/', $subPath), fn($s) => $s !== ''));
        if (empty($segments)) {
            return null;
        }

        $ownerId = $storage->userStorages()->first()?->user_id ?? $userId ?? 1;
        $currentParentId = null;
        $accumulatedPath = '';

        foreach ($segments as $segment) {
            $accumulatedPath = $accumulatedPath === '' ? $segment : $accumulatedPath . '/' . $segment;

            $folder = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_folder', true)
                ->first();

            if ($folder !== null) {
                $currentParentId = $folder->id;
                continue;
            }

            // Trash check: si hay una fila trashada con este path, NO crear
            // una nueva (la fila trashada es canonica hasta restauracion o
            // purga). Ver logica equivalente en createFileFromScan() linea ~610.
            $existingTrashed = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_trashed', true)
                ->first();
            if ($existingTrashed) {
                Log::info('storage_sync.skipped_trashed_collision_folder', [
                    'storage_id' => $storage->id,
                    'path' => $accumulatedPath,
                    'trashed_file_id' => $existingTrashed->id,
                ]);
                // No podemos crear la cadena limpia; abortamos y devolvemos NULL
                // para que el caller sepa que la delegacion no encontro padre.
                return null;
            }

            $created = $this->registry->ensure($storage, $accumulatedPath, [
                'name' => $segment,
                'path' => $accumulatedPath,
                'size' => 0,
                'mime_type' => 'folder',
                'storage_provider_id' => $storage->id,
                'owner_id' => $ownerId,
                'parent_id' => $currentParentId,
                'is_folder' => true,
                'file_modified_at' => null,
                'availability_state' => 'available',
                'last_verified_at' => now(),
                'missing_since_at' => null,
            ]);

            $currentParentId = $created->id;
        }

        return $currentParentId;
    }

    /**
     * Self-healing delegation (change `2026-09-17-self-healing-sync-permissions`).
     *
     * Para cada file row existente en el folder ($storage_id, $parent_id) cuyo
     * absolute path cae bajo un sub-storage mas especifico, lo migra al
     * sub-storage correcto via una sola query SQL bulk.
     *
     * Casos manejados:
     *   - File sin destino: no se toca (ya esta bien delegado)
     *   - File con destino y folder chain limpio: UPDATE storage_provider_id + parent_id
     *   - File con destino que ya tiene file con mismo path: re-apuntar FKs
     *     (transcriptions/shares/media_edit_jobs) al file existente, borrar leak
     *   - File con destino y folder trashed en el chain: skip + log warning
     *
     * Devuelve el numero de files migrados (incluye los borrados por colision).
     */
    private function selfHealDelegationLeak(int $storageId, ?int $parentId): int
    {
        // 1. Detectar candidatos: files en este folder cuyo absolute path cae bajo
        //    un sub-storage mas especifico. Sin cross-join: usamos position() + LIKE.
        $candidates = DB::select("
            SELECT f.id AS file_id, f.path AS file_path, f.storage_provider_id AS origin_id,
                   s1.base_path AS origin_base,
                   s2.id AS target_id, s2.base_path AS target_base
            FROM files f
            JOIN storage_providers s1 ON s1.id = f.storage_provider_id
            CROSS JOIN storage_providers s2
            WHERE s2.duplicate_of_storage_id IS NULL
              AND s2.base_path IS NOT NULL AND s2.base_path <> ''
              AND s2.id != f.storage_provider_id
              AND s2.id != s1.id
              AND s2.base_path != s1.base_path
              AND (s1.base_path || '/' || f.path || '/') LIKE s2.base_path || '/%'
              AND f.parent_id " . ($parentId === null ? "IS NULL" : "= " . (int)$parentId) . "
              AND f.storage_provider_id = ?
              AND NOT f.is_folder AND NOT f.is_trashed
            LIMIT 5000
        ", [$storageId]);

        if (empty($candidates)) return 0;

        $migrated = 0;
        $registry = app(FileRegistry::class);

        foreach ($candidates as $cand) {
            $filePath = trim((string) $cand->file_path, '/');
            $targetBase = rtrim((string) $cand->target_base, '/');
            $relativeToTarget = ltrim(substr(strtolower($filePath), strlen(strtolower($targetBase)) + 1), '/.');
            $subParentPath = trim(dirname($relativeToTarget), '/.');

            // Resolver parent_id en el sub-storage destino
            $parentFolderId = null;
            if ($subParentPath !== '' && $subParentPath !== '.') {
                $parentFolderId = $this->ensureSubstorageFolderChainPublic(
                    (int) $cand->target_id,
                    $subParentPath,
                    null
                );
                if ($parentFolderId === null) {
                    Log::info('storage_sync.self_heal_trashed_collision', [
                        'file_id' => $cand->file_id,
                        'storage_id' => $cand->target_id,
                        'path' => $subParentPath,
                    ]);
                    continue;
                }
            }

            // Detectar colision: ya existe un file con mismo path en el destino
            $existingAtTarget = File::where('storage_provider_id', $cand->target_id)
                ->where('path', $filePath)
                ->where('is_trashed', false)
                ->first();

            if ($existingAtTarget !== null) {
                // Re-apuntar FKs al file existente, luego borrar el leak
                DB::table('transcriptions')->where('file_id', $cand->file_id)->update(['file_id' => $existingAtTarget->id]);
                DB::table('shares')->where('file_id', $cand->file_id)->update(['file_id' => $existingAtTarget->id]);
                DB::table('media_edit_jobs')->where('source_file_id', $cand->file_id)->update(['source_file_id' => $existingAtTarget->id]);
                File::where('id', $cand->file_id)->delete();
                $this->invalidateFolderCache((int) $cand->target_id, $parentFolderId);
                $this->invalidateFolderCache((int) $cand->origin_id, $parentId);
                $migrated++;
                continue;
            }

            // UPDATE: migrar al sub-storage
            File::where('id', $cand->file_id)->update([
                'storage_provider_id' => $cand->target_id,
                'parent_id' => $parentFolderId,
            ]);

            $this->invalidateFolderCache((int) $cand->target_id, $parentFolderId);
            $this->invalidateFolderCache((int) $cand->origin_id, $parentId);

            Log::info('storage_sync.file_migrated_to_substorage', [
                'file_id' => $cand->file_id,
                'from_storage_id' => $cand->origin_id,
                'to_storage_id' => $cand->target_id,
                'path' => $filePath,
            ]);

            $migrated++;
        }

        return $migrated;
    }

    /**
     * Wrapper publico para ensureSubstorageFolderChain. Usado por
     * `selfHealDelegationLeak()` que necesita el mismo comportamiento.
     */
    private function ensureSubstorageFolderChainPublic(int $storageId, string $subPath, ?int $userId): ?int
    {
        $storage = StorageProvider::find($storageId);
        if ($storage === null) return null;
        return $this->ensureSubstorageFolderChain($storage, $subPath, $userId);
    }

    private function markOrphansUnknown(iterable $orphans): void
    {
        foreach ($orphans as $orphan) {
            if ($orphan->availability_state !== 'unknown') {
                $orphan->update([
                    'availability_state' => 'unknown',
                    'last_verified_at' => null,
                    'missing_since_at' => null,
                ]);
            }
        }
    }

    /**
     * Marca huérfanos como 'missing' cuando PruneGuard rechaza por
     * orphan_linked. Preserva el file_id y, con él, la trazabilidad de
     * transcripciones, shares y media_edit_jobs. `missing_since_at` se
     * establece para que la UI pueda mostrar "no disponible desde X".
     */
    private function markOrphansMissing(iterable $orphans, int $storageId, ?int $parentId): void
    {
        $count = 0;
        foreach ($orphans as $orphan) {
            if ($orphan->availability_state === 'missing') {
                continue;
            }
            $orphan->update([
                'availability_state' => 'missing',
                'missing_since_at' => now(),
                'last_verified_at' => now(),
            ]);
            $count++;
        }

        if ($count > 0) {
            Log::info('storage_sync.orphan_marked_missing', [
                'storage_id' => $storageId,
                'parent_id' => $parentId,
                'marked' => $count,
                'reason' => 'orphan_linked — preservando FKs aguas abajo',
            ]);
        }
    }

    /**
     * Detecta si una fila de files esta enlazada por FKs aguas abajo.
     * Las consultas son EXISTS acotados (LIMIT 1): no agregan ni ordenan,
     * asi que un lote de 700k filas no satura el server.
     *
     * Las 3 tablas que enlazan son:
     *  - transcriptions.file_id           (UNIQUE: una por archivo)
     *  - shares.file_id                   (0..N comparticiones)
     *  - media_edit_jobs.source_file_id   (0..N ediciones)
     */
    private function isFileLinked(int $fileId): bool
    {
        static $hasMediaJobsColumn = null;
        if ($hasMediaJobsColumn === null) {
            $hasMediaJobsColumn = DB::selectOne(
                "SELECT 1 AS ok FROM information_schema.columns WHERE table_name = 'media_edit_jobs' AND column_name = 'source_file_id'"
            ) !== null;
        }

        $hasTx = DB::selectOne('SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1', [$fileId]) !== null;
        if ($hasTx) {
            return true;
        }

        $hasShare = DB::selectOne('SELECT 1 AS ok FROM shares WHERE file_id = ? LIMIT 1', [$fileId]) !== null;
        if ($hasShare) {
            return true;
        }

        if ($hasMediaJobsColumn) {
            $hasJob = DB::selectOne('SELECT 1 AS ok FROM media_edit_jobs WHERE source_file_id = ? LIMIT 1', [$fileId]) !== null;
            if ($hasJob) {
                return true;
            }
        }

        return false;
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

                        // skip si el directorio no cambio desde el ultimo sync (solo si no es force).
                        //
                        // Cambio `mis-archivos-substorage-orphan-repair` (2026-09-17):
                        // el `<=` original tenia un agujero. Cuando una carpeta-dia
                        // se crea con todas sus subcarpetas en el mismo burst (ej.
                        // `20260917/` con `imagenes/` y `pdf_paginas_ocr/` adentro),
                        // el `dirMtime` se setea al final del sync con el mtime del
                        // directorio (= momento del ultimo subdir agregado). En el
                        // siguiente cron, `filemtime == file_modified_at`, asi que
                        // el skip se cumplia aunque el contenido nunca se hubiera
                        // escaneado en serio. Cambio a `<` (estricto): si son
                        // iguales, se re-escanea por si acaso.
                        //
                        // Ademas defensa en profundidad: si BD tiene 0 filas
                        // no-trashed bajo el folder pero el disco tiene entradas,
                        // el sync NUNCA paso por aqui (caso reportado). Forzamos
                        // el sync aunque mtime coincida.
                        if (!$force && $folder->file_modified_at !== null && filemtime($realPath) < $folder->file_modified_at->timestamp) {
                            $stats['skipped']++;
                            continue;
                        }
                        if (!$force
                            && File::where('storage_provider_id', $storage->id)
                                ->where('parent_id', $folder->id)
                                ->where('is_trashed', false)
                                ->count() === 0
                            && iterator_count(new \FilesystemIterator($realPath, \FilesystemIterator::SKIP_DOTS)) > 0
                        ) {
                            // BD dice vacio, disco tiene entradas: nunca se escaneo.
                            // Log para auditoria y caemos al sync de abajo.
                            Log::info('storage_sync.cardinality_zero_force_sync', [
                                'storage_id' => $storage->id,
                                'folder_id' => $folder->id,
                                'path' => $folder->path,
                                'reason' => 'bd_count=0 + disk has entries',
                            ]);
                        } elseif (!$force && $folder->file_modified_at !== null && filemtime($realPath) >= $folder->file_modified_at->timestamp) {
                            // mtime coincide o es posterior al file_modified_at y la BD tiene
                            // entradas: skip tradicional (comportamiento previo preservado).
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
