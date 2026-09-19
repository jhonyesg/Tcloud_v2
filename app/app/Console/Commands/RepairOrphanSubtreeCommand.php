<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\FileRegistry;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Repara los archivos con parent_id = NULL en sub-storages que quedaron
 * huerfanos en la raiz porque la delegacion previa no creo la jerarquia
 * de folders intermedia.
 *
 * Contexto del bug (change `mis-archivos-substorage-orphan-repair`,
 * 2026-09-17): `StorageSyncService::createFileFromScan()` delega un
 * archivo a un sub-storage cuyo base_path es prefijo, pero si la carpeta
 * del sub-storage correspondiente al path delegado no existia, dejaba
 * parent_id = NULL. Eso rompe el listado de Mis Archivos:
 * `resolveListingTargets()` no encontraba el folder intermedio y
 * devolvia solo el target del storage padre, asi que la UI veia la
 * carpeta vacia aunque el archivo fisico estuviera en disco.
 *
 * Operacion:
 *  1. Para cada StorageProvider local habilitado, recorre `files` con
 *     parent_id IS NULL AND NOT is_folder AND NOT is_trashed.
 *  2. Parsea el `path` (relativo a base_path). Si esta en la raiz del
 *     storage, no es huerfano: skip.
 *  3. Sube segmento por segmento (raiz hacia abajo) creando las filas
 *     de folder que falten en el sub-storage, usando
 *     `FileRegistry::ensure()` (idempotente por (storage_id, path)).
 *  4. Re-parenta el archivo al folder hoja creado.
 *  5. Incrementa `folder_gen:{storageId}:{parentId}` para invalidar
 *     las caches de folder listing afectadas.
 *
 * Sin migracion de BD. Sin escritura en disco. Solo toca la tabla
 * `files` y el cache `folder_gen:*`.
 *
 * Lock distribuido: `cache:lock('files:repair-orphan-subtree', 3600)`
 * para evitar superposicion con otra instancia. NO bloquea el cron
 * `storage:sync` regular.
 */
class RepairOrphanSubtreeCommand extends Command
{
    protected $signature = 'files:repair-orphan-subtree
                            {--dry-run : Reporta conteos sin modificar nada (default si no se pasa --apply)}
                            {--apply : Ejecuta la reparacion}
                            {--storage= : Limitar a un storage_provider_id}
                            {--chunk=500 : Filas por transaccion}';

    protected $description = 'Repara archivos huerfanos (parent_id NULL) en sub-storages creando los folders faltantes y reparentando.';

    public function handle(StorageSyncService $syncService, FileRegistry $registry): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply y --dry-run son mutuamente excluyentes.');
            return Command::FAILURE;
        }

        $dryRun = !$this->option('apply');
        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : null;
        $chunk = max(50, (int) $this->option('chunk'));

        $lock = Cache::lock('files:repair-orphan-subtree', 3600);
        if (!$lock->get()) {
            $this->error('Otra instancia de files:repair-orphan-subtree esta corriendo (lock tomado).');
            return Command::FAILURE;
        }

        try {
            $storages = StorageProvider::query()
                ->where('type', 'local')
                ->where('enabled', true)
                ->when($storageId !== null, fn($q) => $q->where('id', $storageId))
                ->orderBy('id')
                ->get();

            if ($storages->isEmpty()) {
                $this->warn('No hay storages locales habilitados que coincidan con el filtro.');
                return Command::SUCCESS;
            }

            $this->line($dryRun ? 'Modo: DRY-RUN (no se modifica nada)' : 'Modo: APPLY');
            $this->line("Storage(s) a procesar: {$storages->count()}");
            $this->newLine();

            $totals = [
                'orphans_found' => 0,
                'roots_kept' => 0,
                'folders_created' => 0,
                'files_reparented' => 0,
                'cache_keys_invalidated' => 0,
                'errors' => 0,
            ];

            foreach ($storages as $storage) {
                $stats = $this->repairStorage($storage, $dryRun, $chunk, $registry);
                foreach ($totals as $k => $_) {
                    $totals[$k] += $stats[$k];
                }

                $this->table(
                    ['Storage', 'Huerfanos', 'En raiz (skip)', 'Folders a crear', 'Archivos a reparentar', 'Cache invalid.', 'Errores'],
                    [[
                        "#{$storage->id} {$storage->name}",
                        $stats['orphans_found'],
                        $stats['roots_kept'],
                        $stats['folders_created'],
                        $stats['files_reparented'],
                        $stats['cache_keys_invalidated'],
                        $stats['errors'],
                    ]],
                );
            }

            $this->newLine();
            $this->line('TOTALES:');
            $this->table(
                ['Metrica', 'Valor'],
                [
                    ['Huerfanos detectados', $totals['orphans_found']],
                    ['En raiz (no aplica)', $totals['roots_kept']],
                    ['Folders creados', $totals['folders_created']],
                    ['Archivos re-parentados', $totals['files_reparented']],
                    ['Cache keys invalidadas', $totals['cache_keys_invalidated']],
                    ['Errores', $totals['errors']],
                ],
            );

            Log::info('files.substorage_orphan_repair', [
                'mode' => $dryRun ? 'dry_run' : 'apply',
                'totals' => $totals,
            ]);

            return $totals['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{orphans_found:int,roots_kept:int,folders_created:int,files_reparented:int,cache_keys_invalidated:int,errors:int}
     */
    private function repairStorage(StorageProvider $storage, bool $dryRun, int $chunk, FileRegistry $registry): array
    {
        $stats = [
            'orphans_found' => 0,
            'roots_kept' => 0,
            'folders_created' => 0,
            'files_reparented' => 0,
            'cache_keys_invalidated' => 0,
            'errors' => 0,
        ];

        File::query()
            ->where('storage_provider_id', $storage->id)
            ->whereNull('parent_id')
            ->where('is_folder', false)
            ->where('is_trashed', false)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($storage, $dryRun, $registry, &$stats) {
                foreach ($rows as $file) {
                    $stats['orphans_found']++;

                    $path = trim((string) $file->path, '/');
                    if ($path === '' || !str_contains($path, '/')) {
                        // En la raiz del storage (path sin separadores): no es
                        // huerfano en sentido estricto, es un archivo legitimo
                        // en la raiz. Skip.
                        $stats['roots_kept']++;
                        continue;
                    }

                    $subParentPath = trim(dirname($path), '/.');
                    if ($subParentPath === '' || $subParentPath === '.') {
                        $stats['roots_kept']++;
                        continue;
                    }

                    if ($dryRun) {
                        // Solo contar lo que hariamos.
                        [$wouldCreate, $wouldReparent] = $this->simulateRepair($storage, $file, $subParentPath, $registry);
                        $stats['folders_created'] += $wouldCreate;
                        $stats['files_reparented'] += $wouldReparent;
                        continue;
                    }

                    try {
                        [$created, $reparented, $invalidated] = $this->applyRepair($storage, $file, $subParentPath, $registry);
                        $stats['folders_created'] += $created;
                        $stats['files_reparented'] += $reparented;
                        $stats['cache_keys_invalidated'] += $invalidated;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('files.repair_orphan_subtree_error', [
                            'storage_id' => $storage->id,
                            'file_id' => $file->id,
                            'path' => $file->path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Dry-run: cuenta cuantos folders crearia y cuantos archivos re-parentaria
     * sin tocar la BD. NO muta nada.
     *
     * @return array{0:int,1:int} [folders_to_create, files_to_reparent]
     */
    private function simulateRepair(StorageProvider $storage, File $file, string $subParentPath, FileRegistry $registry): array
    {
        $segments = array_values(array_filter(explode('/', $subParentPath), fn($s) => $s !== ''));
        if (empty($segments)) {
            return [0, 0];
        }

        $wouldCreate = 0;
        $accumulatedPath = '';
        foreach ($segments as $segment) {
            $accumulatedPath = $accumulatedPath === '' ? $segment : $accumulatedPath . '/' . $segment;
            $exists = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_folder', true)
                ->exists();
            if (!$exists) {
                $wouldCreate++;
            }
        }

        return [$wouldCreate, 1];
    }

    /**
     * Aplica la reparacion: crea los folders faltantes y re-parenta el archivo.
     * Tambien invalida el cache de listing afectado.
     *
     * @return array{0:int,1:int,2:int} [folders_created, files_reparented, cache_invalidated]
     */
    private function applyRepair(StorageProvider $storage, File $file, string $subParentPath, FileRegistry $registry): array
    {
        $segments = array_values(array_filter(explode('/', $subParentPath), fn($s) => $s !== ''));
        if (empty($segments)) {
            return [0, 0, 0];
        }

        $ownerId = StorageProvider::canonicalOwnerId($storage->id)
            ?? throw new \RuntimeException("storage {$storage->id} sin owner canonico");
        $currentParentId = null;
        $accumulatedPath = '';
        $foldersCreated = 0;
        $invalidatedKeys = [];

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

            // Si hay una fila trashada con este path, respetar la canonica
            // y abortar la cadena. El operador deberia restaurar o purgar
            // antes de re-intentar.
            $trashedCollision = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_trashed', true)
                ->first();
            if ($trashedCollision) {
                Log::warning('files.repair_trashed_collision_abort', [
                    'storage_id' => $storage->id,
                    'path' => $accumulatedPath,
                    'trashed_file_id' => $trashedCollision->id,
                    'orphan_file_id' => $file->id,
                ]);
                return [$foldersCreated, 0, 0];
            }

            $created = $registry->ensure($storage, $accumulatedPath, [
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
            $foldersCreated++;

            // Invalidar cache de listing del padre del folder recien creado.
            $invalidatedKeys[] = ['storage' => $storage->id, 'parent' => $created->parent_id];
        }

        // Re-parentar el archivo. Tambien invalidar la cache de listing del
        // padre anterior (que era NULL = raiz) y del nuevo padre.
        File::where('id', $file->id)->update(['parent_id' => $currentParentId]);
        $invalidatedKeys[] = ['storage' => $storage->id, 'parent' => null];
        $invalidatedKeys[] = ['storage' => $storage->id, 'parent' => $currentParentId];

        // Deduplicar keys antes de incrementar (Cache::increment es idempotente
        // por key, no por incremento: sumar dos veces al mismo key cuenta doble).
        $uniqueKeys = [];
        foreach ($invalidatedKeys as $k) {
            $key = "folder_gen:{$k['storage']}:" . ($k['parent'] ?? 'null');
            $uniqueKeys[$key] = true;
        }
        foreach (array_keys($uniqueKeys) as $key) {
            Cache::increment($key);
        }

        return [$foldersCreated, 1, count($uniqueKeys)];
    }
}
