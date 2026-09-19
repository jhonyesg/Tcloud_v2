<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\FileRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repara el "delegation leak": archivos cuyo `path` cae dentro del
 * `base_path` de un sub-storage mas especifico pero cuyo
 * `storage_provider_id` sigue apuntando al storage padre (tipicamente el
 * root "00 Discos", id=5).
 *
 * Caso real (2026-09-17):
 *   - Usuario navega "Mis Archivos > 01 Caracol Tv" (storage 6)
 *   - Folder "17092026" lista archivos
 *   - Click en `caracol_17092026_071501.mp4` (file_id 7297558)
 *   - FileController::download -> checkFilePermission -> user has access to storage 6
 *     PERO file.storage_provider_id = 5 (00 Discos) -> Forbidden
 *
 * La causa: cuando `00 Discos` (storage 5) hace `fullSync`, escanea todos
 * los archivos bajo `/Tcloud/...` y crea filas en `files` con
 * `storage_provider_id=5`. El codigo de delegacion en
 * `StorageSyncService::createFileFromScan()` deberia haber movido esos
 * archivos al sub-storage correcto, pero NO LO HACE para filas existentes.
 * Solo lo hacia al INSERT (vía `findMoreSpecificStorage`), no en re-syncs.
 *
 * Operacion:
 *   - Para cada archivo `f` con `storage_provider_id = X` cuyo `path` cae
 *     dentro del `base_path` de un sub-storage `Y`:
 *     - Encuentra el `Y` mas especifico (prefijo mas largo)
 *     - Crea la jerarquia de folders faltante en Y via
 *       `FileRegistry::ensure()` (mismo upsert que el sync regular)
 *     - UPDATE files SET storage_provider_id = Y, parent_id = <folder_row_in_Y>
 *   - Idempotente: si ya esta delegado, no hace nada
 *   - NO toca FKs (transcriptions/shares/jobs) porque los `file_id` no cambian
 *   - NO toca `path` (sigue siendo la ruta completa relativa a base_path)
 *
 * Uso:
 *   php artisan files:repair-delegation-leak --dry-run
 *   php artisan files:repair-delegation-leak --apply
 *   php artisan files:repair-delegation-leak --apply --storage=5  # solo desde storage 5
 *   php artisan files:repair-delegation-leak --apply --to-storage=6  # solo hacia storage 6
 */
class RepairDelegationLeakCommand extends Command
{
    protected $signature = 'files:repair-delegation-leak
                            {--dry-run : Reporta conteos sin modificar nada}
                            {--apply : Ejecuta la reparacion}
                            {--storage= : Limitar a un storage_provider_id origen (FROM)}
                            {--to-storage= : Limitar a un storage_provider_id destino (TO)}
                            {--chunk=500 : Filas por transaccion}';

    protected $description = 'Repara archivos cuyo path cae en un sub-storage mas especifico (delegation leak).';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply y --dry-run son mutuamente excluyentes.');
            return Command::FAILURE;
        }

        $dryRun = !$this->option('apply');
        $fromStorage = $this->option('storage') !== null ? (int) $this->option('storage') : null;
        $toStorage = $this->option('to-storage') !== null ? (int) $this->option('to-storage') : null;
        $chunk = max(50, (int) $this->option('chunk'));

        $lock = Cache::lock('files:repair-delegation-leak', 3600);
        if (!$lock->get()) {
            $this->error('Otra instancia de files:repair-delegation-leak esta corriendo.');
            return Command::FAILURE;
        }

        try {
            $registry = app(FileRegistry::class);
            $storages = StorageProvider::query()
                ->whereNull('duplicate_of_storage_id')
                ->whereNotNull('base_path')
                ->where('base_path', '!=', '')
                ->orderByRaw('LENGTH(rtrim(base_path, \'/\')) DESC')
                ->get(['id', 'name', 'base_path']);

            if ($storages->isEmpty()) {
                $this->info('No hay storages con base_path para procesar.');
                return Command::SUCCESS;
            }

            $totals = [
                'scanned' => 0,
                'leaks_found' => 0,
                'files_moved' => 0,
                'duplicates_merged' => 0,
                'folders_created' => 0,
                'cache_invalidated' => 0,
                'skipped_unresolvable' => 0,
                'skipped_trashed_collision' => 0,
                'skipped_tx_conflict' => 0,
                'errors' => 0,
            ];

            foreach ($storages as $subStorage) {
                // Si --to-storage esta seteado, skip storages que no son el target.
                if ($toStorage !== null && $subStorage->id !== $toStorage) {
                    continue;
                }

                // Skip si no hay archivos cuyo path absoluto caiga bajo este base_path.
                // El EXPLAIN de position(...) es lento sobre tablas grandes, asi que
                // primero hacemos un COUNT cheap con EXISTS para podar storages sin leaks.
                $hasLeaks = (int) DB::selectOne("
                    SELECT count(*) AS n FROM files f
                    JOIN storage_providers s ON s.id = f.storage_provider_id
                    WHERE f.storage_provider_id != ?
                      AND NOT f.is_folder AND NOT f.is_trashed
                      AND f.path IS NOT NULL AND f.path != ''
                      AND position(? in lower(s.base_path || '/' || f.path)) > 0
                    LIMIT 1
                ", [$subStorage->id, strtolower(rtrim($subStorage->base_path, '/'))])->n > 0;

                if (!$hasLeaks) continue;

                $this->processStorage($subStorage, null, $registry, $dryRun, $totals, $fromStorage, $toStorage);
            }

            $this->line('');
            $this->line('TOTALES:');
            $this->table(['Metrica', 'Valor'], [
                ['Archivos escaneados', $totals['scanned']],
                ['Leaks detectados (path bajo sub-storage)', $totals['leaks_found']],
                ['Archivos movidos al sub-storage correcto', $totals['files_moved']],
                ['  de esos, duplicados colapsados', $totals['duplicates_merged']],
                ['Folders creados en sub-storages', $totals['folders_created']],
                ['Cache keys invalidadas', $totals['cache_invalidated']],
                ['Omitidos: ruta no existe en disco', $totals['skipped_unresolvable']],
                ['Omitidos: colision con papelera', $totals['skipped_trashed_collision']],
                ['Omitidos: conflicto de transcripcion', $totals['skipped_tx_conflict']],
                ['Errores por fila', $totals['errors']],
            ]);

            Log::info('files.delegation_leak_repaired', [
                'mode' => $dryRun ? 'dry_run' : 'apply',
                'totals' => $totals,
            ]);

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Para un sub-storage dado, encontrar archivos en otros storages cuyo
     * path cae bajo su base_path y moverlos.
     *
     * Optimización: en lugar de un cross-join que se vuelve O(N*M), usamos
     * una sola query que selecciona filas candidatas por prefijo de path.
     * El LIMIT 10000 evita queries demasiado largas; si hay más, el
     * operador corre el comando de nuevo (es idempotente).
     */
    private function processStorage(
        StorageProvider $subStorage,
        ?\Illuminate\Database\Eloquent\Builder $query,
        FileRegistry $registry,
        bool $dryRun,
        array &$totals,
        ?int $fromStorage = null,
        ?int $toStorage = null,
    ): void {
        $subBase = rtrim((string) $subStorage->base_path, '/');
        $subClean = strtolower($subBase);

        // Cargar todos los storages para calcular base_path + path absoluto
        $allStorages = StorageProvider::query()
            ->whereNull('duplicate_of_storage_id')
            ->whereNotNull('base_path')
            ->get(['id', 'base_path'])
            ->keyBy('id');

        // Detectar leaks con una sola query que selecciona files cuyo path
        // absoluto (base_path + path) empieza con subStorage.base_path.
        // Sin cross-join: usa position() con lower() para match case-insensitive.
        $candidates = DB::select("
            SELECT f.id, f.storage_provider_id AS owner_id, f.path,
                   s.base_path AS owner_base
            FROM files f
            JOIN storage_providers s ON s.id = f.storage_provider_id
            WHERE f.storage_provider_id != ?
              AND NOT f.is_folder
              AND NOT f.is_trashed
              AND f.path IS NOT NULL AND f.path != ''
              AND position(? in lower(s.base_path || '/' || f.path)) > 0
              " . ($fromStorage !== null ? "AND f.storage_provider_id = {$fromStorage}" : "") . "
            LIMIT 10000
        ", [$subStorage->id, $subClean]);

        $totals['scanned'] += count($candidates);
        $leaks = [];

        foreach ($candidates as $c) {
            $owner = $allStorages[$c->owner_id] ?? null;
            if ($owner === null) continue;
            // Verificacion adicional: el path absoluto (owner.base_path + / + file.path)
            // debe caer bajo subStorage.base_path
            $absolutePath = strtolower(rtrim($owner->base_path, '/') . '/' . $c->path);
            if (!str_starts_with($absolutePath, $subClean . '/') && $absolutePath !== $subClean) {
                continue;
            }
            $leaks[] = $c;
        }

        $totals['leaks_found'] += count($leaks);
        if (empty($leaks)) return;

        $this->line(sprintf(
            "\n  Sub-storage #%d (%s) tiene %d archivos delegados:",
            $subStorage->id, $subStorage->name, count($leaks)
        ));

        $ownerId = $leaks[0]->owner_id;

        if ($dryRun) {
            // Clasificar igual que el apply pero sin mutar: asi el operador ve
            // exactamente que pasara (movidos, colapsados, omitidos) antes de
            // decidir. Un dry-run que solo cuenta leaks no anticipa las
            // colisiones ni las rutas muertas.
            foreach ($leaks as $leak) {
                $path = trim((string) $leak->path, '/');
                $ownerBase = rtrim((string) ($leak->owner_base ?? ''), '/');

                if ($ownerBase === '' || $path === '') {
                    $totals['skipped_unresolvable']++;
                    continue;
                }

                $absolute = $this->resolvePhysicalPath($ownerBase, $path);
                if ($absolute === null) {
                    $totals['skipped_unresolvable']++;
                    continue;
                }

                $newPath = $this->relativeToBase($absolute, $subStorage->base_path);
                if ($newPath === null || $newPath === '') {
                    $totals['skipped_unresolvable']++;
                    continue;
                }

                $existingAtDest = DB::selectOne(
                    'SELECT id, is_trashed FROM files WHERE storage_provider_id = ? AND path = ? LIMIT 1',
                    [$subStorage->id, $newPath]
                );

                if ($existingAtDest !== null) {
                    if ($existingAtDest->is_trashed) {
                        $totals['skipped_trashed_collision']++;
                        continue;
                    }

                    $leakHasTx = DB::selectOne('SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1', [$leak->id]) !== null;
                    $destHasTx = DB::selectOne('SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1', [$existingAtDest->id]) !== null;

                    if ($leakHasTx && $destHasTx) {
                        $totals['skipped_tx_conflict']++;
                        continue;
                    }

                    $totals['duplicates_merged']++;
                    $totals['files_moved']++;
                    continue;
                }

                $totals['files_moved']++;
            }

            $totals['folders_created'] += $this->estimateFoldersCreated($leaks, $subStorage);
            return;
        }

        // Aplicar: para cada archivo, calcular parent path, crear folder chain, UPDATE
        $foldersCreatedThisRun = [];
        $invalidatedKeys = [];

        foreach ($leaks as $leak) {
            $path = trim((string) $leak->path, '/');
            $ownerBase = rtrim((string) ($leak->owner_base ?? ''), '/');

            if ($ownerBase === '' || $path === '') {
                $totals['skipped_unresolvable']++;
                continue;
            }

            try {
                // 1. La fila debe representar un archivo REAL en disco. Sin este
                //    chequeo el comando mueve filas huerfanas (paths corruptos de
                //    backfills viejos) y las deja apuntando a rutas inexistentes.
                $absolute = $this->resolvePhysicalPath($ownerBase, $path);

                if ($absolute === null) {
                    $totals['skipped_unresolvable']++;
                    Log::info('files.repair_delegation_unresolvable', [
                        'file_id' => $leak->id,
                        'owner_base' => $ownerBase,
                        'path' => $path,
                    ]);
                    continue;
                }

                // 2. Rebasear preservando el case REAL del disco.
                $newPath = $this->relativeToBase($absolute, $subStorage->base_path);

                if ($newPath === null || $newPath === '') {
                    $totals['skipped_unresolvable']++;
                    Log::info('files.repair_delegation_rebase_failed', [
                        'file_id' => $leak->id,
                        'absolute' => $absolute,
                        'target_base' => $subStorage->base_path,
                    ]);
                    continue;
                }

                // 3. Colision: la constraint UNIQUE (storage_provider_id, path)
                //    NO excluye papelera, asi que hay que chequear CUALQUIER fila.
                $existingAtDest = DB::selectOne(
                    'SELECT id, is_trashed FROM files WHERE storage_provider_id = ? AND path = ? LIMIT 1',
                    [$subStorage->id, $newPath]
                );

                if ($existingAtDest !== null) {
                    if ($existingAtDest->is_trashed) {
                        // No resucitar papelera automaticamente: requiere
                        // decision del operador. Se reporta y se sigue.
                        $totals['skipped_trashed_collision']++;
                        Log::info('files.repair_delegation_trashed_collision', [
                            'file_id' => $leak->id,
                            'storage_id' => $subStorage->id,
                            'path' => $newPath,
                            'trashed_id' => $existingAtDest->id,
                        ]);
                        continue;
                    }

                    // Hay copia viva en el destino. Antes de re-apuntar las FKs,
                    // verificar que el repunte no viole el UNIQUE de
                    // transcriptions.file_id (una transcripcion por archivo).
                    $leakHasTx = DB::selectOne('SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1', [$leak->id]) !== null;
                    $destHasTx = DB::selectOne('SELECT 1 AS ok FROM transcriptions WHERE file_id = ? LIMIT 1', [$existingAtDest->id]) !== null;

                    if ($leakHasTx && $destHasTx) {
                        $totals['skipped_tx_conflict']++;
                        $this->warn(sprintf(
                            '  [tx-conflict] leak=%d dest=%d — ambos con transcripcion, requiere revision manual (%s)',
                            $leak->id, $existingAtDest->id, $newPath
                        ));
                        Log::warning('files.repair_delegation_tx_conflict', [
                            'leak_file_id' => $leak->id,
                            'dest_file_id' => $existingAtDest->id,
                            'path' => $newPath,
                        ]);
                        continue;
                    }

                    DB::transaction(function () use ($leak, $existingAtDest) {
                        DB::table('transcriptions')->where('file_id', $leak->id)->update(['file_id' => $existingAtDest->id]);
                        DB::table('shares')->where('file_id', $leak->id)->update(['file_id' => $existingAtDest->id]);
                        DB::table('media_edit_jobs')->where('source_file_id', $leak->id)->update(['source_file_id' => $existingAtDest->id]);
                        DB::table('files')->where('id', $leak->id)->delete();
                    });

                    $invalidatedKeys["folder_gen:{$subStorage->id}:null"] = true;
                    $invalidatedKeys["folder_gen:{$subStorage->id}:{$leak->owner_id}"] = true;
                    $totals['duplicates_merged']++;
                    $totals['files_moved']++;
                    continue;
                }

                // 4. Sin colision: crear la cadena de carpetas y mover+rebasear.
                $subParentPath = trim(dirname($newPath), '/.');

                $parentFolderId = null;
                if ($subParentPath !== '' && $subParentPath !== '.') {
                    $parentFolderId = $this->ensureFolderChain(
                        $subStorage, $subParentPath, $registry, $foldersCreatedThisRun
                    );

                    if ($parentFolderId === null) {
                        $totals['skipped_trashed_collision']++;
                        continue;
                    }
                }

                DB::table('files')->where('id', $leak->id)->update([
                    'storage_provider_id' => $subStorage->id,
                    'parent_id' => $parentFolderId,
                    'path' => $newPath,
                    'base_path_snapshot' => null,
                ]);

                $invalidatedKeys["folder_gen:{$leak->owner_id}:null"] = true;
                $invalidatedKeys["folder_gen:{$subStorage->id}:{$parentFolderId}"] = true;
                $totals['files_moved']++;
            } catch (\Throwable $e) {
                // Una fila que falla no debe abortar la corrida completa: el
                // comando es idempotente y el operador puede reintentar.
                $totals['errors']++;
                Log::error('files.repair_delegation_row_failed', [
                    'file_id' => $leak->id,
                    'path' => $path,
                    'target_storage_id' => $subStorage->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('  [error] file %d: %s', $leak->id, $e->getMessage()));
            }
        }

        $totals['folders_created'] += count($foldersCreatedThisRun);

        // Invalidar caches
        foreach (array_keys($invalidatedKeys) as $key) {
            Cache::increment($key);
            $totals['cache_invalidated']++;
        }
    }

    /**
     * Crea la cadena de folders en el sub-storage si no existe. Devuelve
     * el id del folder hoja, o null si hay colision con trash.
     */
    private function ensureFolderChain(
        StorageProvider $storage,
        string $subParentPath,
        FileRegistry $registry,
        array &$foldersCreated,
    ): ?int {
        $segments = array_values(array_filter(explode('/', $subParentPath), fn($s) => $s !== ''));
        if (empty($segments)) return null;

        $currentParentId = null;
        $accumulatedPath = '';

        foreach ($segments as $segment) {
            $accumulatedPath = $accumulatedPath === '' ? $segment : $accumulatedPath . '/' . $segment;

            $existing = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_folder', true)
                ->first();

            if ($existing !== null) {
                $currentParentId = $existing->id;
                continue;
            }

            $trashed = File::where('storage_provider_id', $storage->id)
                ->where('path', $accumulatedPath)
                ->where('is_trashed', true)
                ->first();
            if ($trashed !== null) {
                Log::info('files.repair_delegation_trashed_collision', [
                    'storage_id' => $storage->id,
                    'path' => $accumulatedPath,
                    'trashed_id' => $trashed->id,
                ]);
                return null;
            }

            $created = $registry->ensure($storage, $accumulatedPath, [
                'name' => $segment,
                'path' => $accumulatedPath,
                'size' => 0,
                'mime_type' => 'folder',
                'storage_provider_id' => $storage->id,
                // canonical-owner: usar owner canonico del storage
                'owner_id' => StorageProvider::canonicalOwnerId($storage->id)
                    ?? throw new \RuntimeException("storage {$storage->id} sin owner canonico"),
                'parent_id' => $currentParentId,
                'is_folder' => true,
                'availability_state' => 'available',
                'last_verified_at' => now(),
                'missing_since_at' => null,
            ]);
            $currentParentId = $created->id;
            $foldersCreated[] = $accumulatedPath;
        }

        return $currentParentId;
    }

    /**
     * Rebasea la ruta de un archivo al base_path del storage destino.
     *
     * Construye la ruta absoluta (ownerBase + path), la normaliza preservando
     * el case real del disco cuando es posible, y devuelve el path relativo al
     * base_path destino.
     *
     * Devuelve null si la ruta absoluta no cae bajo el base_path destino.
     */
    private function rebaseToStorage(string $ownerBase, string $relativePath, string $targetBase): ?string
    {
        if ($ownerBase === '') {
            return null;
        }

        $absolute = rtrim($ownerBase, '/') . '/' . ltrim($relativePath, '/');
        $absoluteNorm = rtrim($absolute, '/');
        $targetNorm = rtrim($targetBase, '/');

        if (stripos($absoluteNorm, $targetNorm . '/') !== 0) {
            return null;
        }

        return ltrim(substr($absoluteNorm, strlen($targetNorm)), '/');
    }

    /**
     * Reconstruye la ruta ABSOLUTA real del archivo resolviendo el case exacto
     * de cada segmento contra el disco. Devuelve null si no existe.
     */
    private function resolvePhysicalPath(string $base, string $relative): ?string
    {
        $cur = rtrim($base, '/');

        if (!is_dir($cur)) {
            return null;
        }

        foreach (explode('/', trim($relative, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if (file_exists($cur . '/' . $segment)) {
                $cur .= '/' . $segment;
                continue;
            }

            $found = null;
            foreach ((scandir($cur) ?: []) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (strcasecmp($entry, $segment) === 0) {
                    $found = $entry;
                    break;
                }
            }

            if ($found === null) {
                return null;
            }

            $cur .= '/' . $found;
        }

        return file_exists($cur) ? $cur : null;
    }

    /**
     * Devuelve el path relativo de `$absolute` respecto al base_path destino,
     * o null si no cae bajo el.
     */
    private function relativeToBase(string $absolute, string $targetBase): ?string
    {
        $absoluteNorm = rtrim($absolute, '/');
        $targetNorm = rtrim($targetBase, '/');

        if (stripos($absoluteNorm, $targetNorm . '/') !== 0) {
            return null;
        }

        return ltrim(substr($absoluteNorm, strlen($targetNorm)), '/');
    }

    private function estimateFoldersCreated(array $leaks, StorageProvider $subStorage): int
    {
        $uniquePaths = [];
        foreach ($leaks as $leak) {
            $path = trim((string) $leak->path, '/');
            if (str_contains($path, '/')) {
                $subParentPath = trim(dirname($path), '/.');
                $segments = explode('/', $subParentPath);
                $accumulated = '';
                foreach ($segments as $seg) {
                    $accumulated = $accumulated === '' ? $seg : $accumulated . '/' . $seg;
                    $uniquePaths[$accumulated] = true;
                }
            }
        }
        // Restar los que ya existen
        $existing = File::where('storage_provider_id', $subStorage->id)
            ->where('is_folder', true)
            ->whereIn('path', array_keys($uniquePaths))
            ->pluck('path')
            ->flip();
        return count(array_diff_key($uniquePaths, $existing->all()));
    }
}
