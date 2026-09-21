<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeOrphanRootsCommand extends Command
{
    protected $signature = 'files:purge-orphan-roots
                            {--dry-run : Solo reporta, no modifica nada (default si no se pasa --apply)}
                            {--apply : Aplica la limpieza con snapshot pre-cambio}
                            {--yes : Omite la confirmacion interactiva}
                            {--storage= : Limita a un storage_provider_id concreto (default 5 = 00 Discos)}';

    protected $description = 'Limpia archivos (no carpetas) con parent_id IS NULL del root de 00 Discos: borra los que duplican otro storage, reasigna los que caen bajo un sub-storage.';

    public function handle(StorageSyncService $syncService): int
    {
        $dryRun = !$this->option('apply');
        if ($dryRun) {
            $this->warn('Sin --apply: modo dry-run. La BD no sera modificada.');
        }

        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : 5;

        $this->info("Procesando archivos raiz huerfanos del storage {$storageId}...");

        $orphans = $this->loadOrphans($storageId);
        if ($orphans->isEmpty()) {
            $this->info('No hay archivos raiz huerfanos. Nada que limpiar.');
            return Command::SUCCESS;
        }

        $this->info("Archivos candidatos: {$orphans->count()}");
        $this->newLine();

        $plan = [];
        $stats = ['drop_duplicate' => 0, 'drop_internal_dup' => 0, 'reassign' => 0, 'keep_root' => 0];

        foreach ($orphans as $orphan) {
            $decision = $this->classifyOrphan($orphan, $storageId);
            $plan[] = $decision;
            $stats[$decision['action']]++;
        }

        $this->table(
            ['storage_id', 'archivos_drop_dup_otro', 'archivos_drop_dup_interno', 'archivos_reassign', 'archivos_keep_root', 'total'],
            [[
                $storageId,
                number_format($stats['drop_duplicate']),
                number_format($stats['drop_internal_dup']),
                number_format($stats['reassign']),
                number_format($stats['keep_root']),
                number_format(array_sum($stats)),
            ]]
        );

        $this->newLine();
        $this->line('Resumen por destino:');

        $byDest = collect($plan)
            ->groupBy(fn ($p) => $p['action'] . ':' . ($p['destination'] ?? '(ninguno)'))
            ->map(fn ($g) => $g->count())
            ->sortDesc();
        foreach ($byDest->take(20) as $key => $cnt) {
            [$action, $dest] = explode(':', $key, 2);
            $label = match ($action) {
                'drop_duplicate' => "DUP contra storage {$dest}",
                'drop_internal_dup' => 'DUP dentro del propio storage 5',
                'reassign' => "REASIGNAR al storage {$dest}",
                'keep_root' => 'MANTENER en root (sin destino claro)',
                default => $key,
            };
            $this->line("  " . str_pad($label, 60) . ' ' . number_format($cnt));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: ninguna modificacion realizada. Use --apply para ejecutar.');
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Se creara snapshot y se procesaran {$orphans->count()} archivos. ¿Continuar?", false)) {
            $this->warn('Cancelado por el operador.');
            return Command::SUCCESS;
        }

        $ts = date('Ymd_His');
        $snapshotTable = "files_orphan_roots_pre_purge_{$ts}";

        $idsToSnapshot = collect($plan)->pluck('id')->all();
        $this->info("Creando snapshot {$snapshotTable} con " . count($idsToSnapshot) . " filas...");

        try {
            DB::statement("
                CREATE TABLE {$snapshotTable} AS
                SELECT * FROM files WHERE id IN (" . implode(',', array_map('intval', $idsToSnapshot)) . ")
            ");
        } catch (\Throwable $e) {
            $this->error('No se pudo crear el snapshot: ' . $e->getMessage());
            Log::error('files_orphan_roots.snapshot_failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        $deleted = 0;
        $reassigned = 0;
        $errors = 0;

        $reassignRows = collect($plan)->where('action', 'reassign');
        $dropRows = collect($plan)->whereIn('action', ['drop_duplicate', 'drop_internal_dup']);

        // Delete drops (chunks of 500)
        foreach ($dropRows->chunk(500) as $batch) {
            $deleted += File::whereIn('id', $batch->pluck('id'))->delete();
        }

        // Reassigns
        foreach ($reassignRows as $row) {
            try {
                File::where('id', $row['id'])->update(['storage_provider_id' => (int) $row['destination']]);
                $reassigned++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('files_orphan_roots.reassign_error', [
                    'file_id' => $row['id'],
                    'destination' => $row['destination'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('files_orphan_roots.completed', [
            'snapshot' => $snapshotTable,
            'deleted_duplicates' => $deleted,
            'reassigned' => $reassigned,
            'errors' => $errors,
            'storage_id' => $storageId,
        ]);

        $this->newLine();
        $this->info("Archivos borrados (duplicados): {$deleted}");
        $this->info("Archivos reasignados a sub-storage: {$reassigned}");
        $this->info("Errores: {$errors}");
        $this->info("Snapshot persistido: {$snapshotTable}");

        // Invalidate caches: drop the deleted ones and reassigned storage's root caches.
        $syncService->invalidateFolderCache($storageId, null);
        foreach ($reassignRows->pluck('destination')->unique() as $destId) {
            $syncService->invalidateFolderCache((int) $destId, null);
        }
        $this->info('Caches invalidadas.');

        $this->verifyFinalState($storageId);

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function loadOrphans(int $storageId): \Illuminate\Support\Collection
    {
        return DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('parent_id', null)
            ->where('is_folder', false)
            ->orderBy('id')
            ->get(['id', 'name', 'path', 'file_modified_at', 'storage_provider_id']);
    }

    /**
     * Classify orphan:
     *   - drop_duplicate: existe OTRA fila con mismo `name` en OTRO storage
     *     con `path` correcto (sub-storage).
     *   - reassign: existe sub-storage cuyo base_path es prefijo de `path`.
     *   - keep_root: ninguno; queda en root (revision manual).
     */
    private ?\Illuminate\Support\Collection $storageNameMap = null;
    private ?array $subStorageCache = null;

    /**
     * Mapa name -> [storage_ids_with_this_name] precargado SOLO para los
     * nombres que importan (los huerfanos del storage actual). Una sola query.
     */
    private function getStorageNameMap(int $parentStorageId): \Illuminate\Support\Collection
    {
        if ($this->storageNameMap === null) {
            $orphanNames = DB::table('files')
                ->where('storage_provider_id', $parentStorageId)
                ->where('parent_id', null)
                ->where('is_folder', false)
                ->pluck('name')
                ->unique()
                ->values();

            if ($orphanNames->isEmpty()) {
                $this->storageNameMap = collect();
                return $this->storageNameMap;
            }

            $this->storageNameMap = DB::table('files')
                ->where('is_folder', false)
                ->whereNotNull('parent_id')
                ->whereIn('name', $orphanNames)
                ->select('name', 'storage_provider_id')
                ->get()
                ->groupBy('name')
                ->map(fn ($rows) => $rows->pluck('storage_provider_id')->unique()->values()->all());
        }
        return $this->storageNameMap;
    }

    private function classifyOrphan(object $orphan, int $parentStorageId): array
    {
        $name = $orphan->name;
        $path = $orphan->path;

        $nameMap = $this->getStorageNameMap($parentStorageId);
        $duplicates = $nameMap->get($name, []);

        $otherStorage = collect($duplicates)->first(fn ($id) => (int) $id !== $parentStorageId);

        // Caso 1: hay duplicado con OTRO storage -> DROP (es duplicado en otro)
        if ($otherStorage !== null) {
            return [
                'id' => (int) $orphan->id,
                'name' => $name,
                'path' => $path,
                'action' => 'drop_duplicate',
                'destination' => (int) $otherStorage,
            ];
        }

        // Caso 2: hay duplicado en el MISMO storage 5 (mismo base_path, distinto path)
        // -> DROP (es duplicado dentro de la raiz misma)
        if (in_array($parentStorageId, $duplicates, true)) {
            return [
                'id' => (int) $orphan->id,
                'name' => $name,
                'path' => $path,
                'action' => 'drop_internal_dup',
                'destination' => null,
            ];
        }

        // Caso 3: reasignar a sub-storage segun base_path
        $subStorageId = $this->resolveSubStorage($path, $parentStorageId);
        if ($subStorageId !== null) {
            return [
                'id' => (int) $orphan->id,
                'name' => $name,
                'path' => $path,
                'action' => 'reassign',
                'destination' => (int) $subStorageId,
            ];
        }

        // Caso 4: no hay duplicado ni sub-storage match -> KEEP (revision manual)
        return [
            'id' => (int) $orphan->id,
            'name' => $name,
            'path' => $path,
            'action' => 'keep_root',
            'destination' => null,
        ];
    }

    private function resolveSubStorage(string $path, int $parentStorageId): ?int
    {
        $parent = StorageProvider::find($parentStorageId);
        if (!$parent) {
            return null;
        }
        $parentPath = rtrim($parent->base_path, '/');

        if (empty($this->subStorageCache)) {
            $this->subStorageCache = StorageProvider::query()
                ->where('id', '<>', $parentStorageId)
                ->whereNotNull('base_path')
                ->get(['id', 'base_path'])
                ->mapWithKeys(fn ($s) => [(int) $s->id => rtrim((string) $s->base_path, '/')])
                ->toArray();
        }

        // Find the longest base_path that is a prefix of parentPath+'/'+path.
        // That's the deepest storage that owns this orphan.
        $fullPath = $parentPath . '/' . ltrim($path, '/');
        $best = null;
        $bestLen = -1;
        foreach ($this->subStorageCache as $id => $base) {
            if ($base === '') continue;
            if (str_starts_with($fullPath, $base . '/') && strlen($base) > $bestLen) {
                $bestLen = strlen($base);
                $best = (int) $id;
            }
        }

        return $best;
    }

    private function verifyFinalState(int $storageId): void
    {
        $remaining = DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('parent_id', null)
            ->where('is_folder', false)
            ->count();

        $this->newLine();
        if ($remaining === 0) {
            $this->info('<fg=green>OK</> Storage ' . $storageId . ' sin archivos raiz huerfanos.');
        } else {
            $this->warn("Quedan {$remaining} archivos raiz en storage {$storageId} (esperado 0).");
        }
    }
}
