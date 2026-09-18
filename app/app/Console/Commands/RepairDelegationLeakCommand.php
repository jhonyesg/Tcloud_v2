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
                'folders_created' => 0,
                'cache_invalidated' => 0,
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
                ['Folders creados en sub-storages', $totals['folders_created']],
                ['Cache keys invalidadas', $totals['cache_invalidated']],
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
            $totals['files_moved'] += count($leaks);
            $totals['folders_created'] += $this->estimateFoldersCreated($leaks, $subStorage);
            return;
        }

        // Aplicar: para cada archivo, calcular parent path, crear folder chain, UPDATE
        $foldersCreatedThisRun = [];
        $invalidatedKeys = [];

        foreach ($leaks as $leak) {
            $path = trim((string) $leak->path, '/');
            if ($path === '' || !str_contains($path, '/')) {
                // Archivo en raiz del sub-storage (no deberia pasar si hay delegation leak,
                // pero por seguridad): parent_id = NULL
                DB::table('files')->where('id', $leak->id)->update([
                    'storage_provider_id' => $subStorage->id,
                    'parent_id' => null,
                ]);
                $totals['files_moved']++;
                continue;
            }

            $subParentPath = trim(dirname($path), '/.');

            // Crear jerarquia de folders en el sub-storage
            $parentFolderId = $this->ensureFolderChain(
                $subStorage, $subParentPath, $registry, $foldersCreatedThisRun
            );

            if ($parentFolderId === null) {
                // Collision con trashed; skip
                continue;
            }

            // Verificar si ya existe un file con (sub_storage_id, file_path) — esto
            // pasa cuando storage 132 (sub) ya tiene su propia copia del file porque
            // el cron del sub-storage escaneo el mismo archivo. El UNIQUE constraint
            // (storage_provider_id, path) impide el UPDATE directo.
            $existingAtDest = File::where('storage_provider_id', $subStorage->id)
                ->where('path', $path)
                ->where('is_trashed', false)
                ->first();
            if ($existingAtDest !== null) {
                // Hay una copia en el destino. Re-apuntar FKs del leak al file
                // existente, luego borrar el leak. Esto preserva el historial.
                $txUpdated = DB::table('transcriptions')->where('file_id', $leak->id)->update(['file_id' => $existingAtDest->id]);
                $shUpdated = DB::table('shares')->where('file_id', $leak->id)->update(['file_id' => $existingAtDest->id]);
                $jobUpdated = DB::table('media_edit_jobs')->where('source_file_id', $leak->id)->update(['source_file_id' => $existingAtDest->id]);
                $fksRepointed = $txUpdated + $shUpdated + $jobUpdated;
                $invalidatedKeys["folder_gen:{$subStorage->id}:{$parentFolderId}"] = true;
                $invalidatedKeys["folder_gen:{$subStorage->id}:null"] = true;
                File::where('id', $leak->id)->delete();
                $totals['files_moved']++;
                continue;
            }

            DB::table('files')->where('id', $leak->id)->update([
                'storage_provider_id' => $subStorage->id,
                'parent_id' => $parentFolderId,
            ]);

            $invalidatedKeys["folder_gen:{$leak->owner_id}:null"] = true;
            $invalidatedKeys["folder_gen:{$subStorage->id}:{$parentFolderId}"] = true;

            $totals['files_moved']++;
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
                'owner_id' => $storage->userStorages()->first()?->user_id ?? 1,
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
