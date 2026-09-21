<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeGhostFoldersCommand extends Command
{
    protected $signature = 'files:purge-ghost-folders
                            {--dry-run : Solo reporta, no modifica nada (default si no se pasa --apply)}
                            {--apply : Aplica el borrado con snapshot pre-DELETE}
                            {--yes : Omite la confirmacion interactiva}
                            {--storage= : Limita a un storage_provider_id concreto}';

    protected $description = 'Borra de files las carpetas con file_modified_at IS NULL cuyo path NO existe en disco.';

    public function handle(StorageSyncService $syncService): int
    {
        if (!$this->option('apply')) {
            $this->warn('Sin --apply: modo dry-run. La BD no sera modificada.');
            $dryRun = true;
        } else {
            $dryRun = false;
        }

        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : null;

        $candidates = $this->findCandidates($storageId);

        if ($candidates->isEmpty()) {
            $this->info('No hay carpetas fantasma. Nada que purgar.');
            return Command::SUCCESS;
        }

        $byStorage = $candidates->groupBy('storage_provider_id');
        $this->info("Carpetas candidatas: {$candidates->count()} (en {$byStorage->count()} storages)");
        $this->newLine();

        $rows = [];
        foreach ($byStorage as $sid => $group) {
            $storage = StorageProvider::find($sid);
            $rows[] = [
                $sid,
                $storage?->name ?? '?',
                $storage?->base_path ?? '?',
                $group->count(),
                $group->min('created_at'),
                $group->max('created_at'),
            ];
        }

        $this->table(['storage_id', 'nombre', 'base_path', 'candidatas', 'mas_vieja', 'mas_nueva'], $rows);

        $this->newLine();
        $this->line('Detalle por storage:');
        foreach ($byStorage as $sid => $group) {
            $this->line("  storage {$sid}:");
            foreach ($group->take(20) as $g) {
                $this->line(sprintf('    id=%d name=%s path=%s created=%s',
                    $g->id,
                    $g->name,
                    $g->path,
                    $g->created_at,
                ));
            }
            if ($group->count() > 20) {
                $this->line('    ... ' . ($group->count() - 20) . ' mas');
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: ninguna modificacion realizada. Use --apply para ejecutar.');
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Se creara snapshot y se borraran {$candidates->count()} carpetas. ¿Continuar?", false)) {
            $this->warn('Cancelado por el operador.');
            return Command::SUCCESS;
        }

        $ts = date('Ymd_His');
        $snapshotTable = "files_ghost_pre_purge_{$ts}";

        $ids = $candidates->pluck('id')->all();
        $this->info("Creando snapshot {$snapshotTable} con {$candidates->count()} filas...");

        try {
            DB::statement("
                CREATE TABLE {$snapshotTable} AS
                SELECT * FROM files WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")
            ");
        } catch (\Throwable $e) {
            $this->error('No se pudo crear el snapshot: ' . $e->getMessage());
            Log::error('files_purge_ghost.snapshot_failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        $affectedStorages = $byStorage->keys()->all();
        $deletedTotal = 0;
        $chunk = 500;

        foreach (array_chunk($ids, $chunk) as $batch) {
            $deleted = File::whereIn('id', $batch)->delete();
            $deletedTotal += $deleted;
        }

        foreach ($affectedStorages as $sid) {
            $syncService->invalidateFolderCache($sid, null);
        }

        Log::info('files_purge_ghost.completed', [
            'snapshot' => $snapshotTable,
            'deleted' => $deletedTotal,
            'storages' => $affectedStorages,
        ]);

        $this->newLine();
        $this->info("Filas eliminadas: {$deletedTotal}");
        $this->info("Snapshot persistido: {$snapshotTable}");
        $this->info('Caches de listado invalidadas: ' . count($affectedStorages) . ' storages.');

        $this->verifyFinalState();

        return Command::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function findCandidates(?int $storageId): \Illuminate\Support\Collection
    {
        $query = DB::table('files as f')
            ->join('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
            ->where('f.is_folder', true)
            ->whereNull('f.parent_id')
            ->whereNull('f.file_modified_at')
            ->orderBy('f.storage_provider_id')
            ->orderBy('f.id')
            ->select('f.id', 'f.name', 'f.path', 'f.storage_provider_id', 'f.created_at', 'sp.base_path');

        if ($storageId !== null) {
            $query->where('f.storage_provider_id', $storageId);
        }

        $rows = $query->get();

        return $rows->filter(function ($row) {
            if (!is_string($row->base_path) || $row->base_path === '') {
                return false;
            }
            $fullPath = rtrim($row->base_path, '/') . '/' . ltrim($row->path, '/');
            return !is_dir($fullPath);
        })->values();
    }

    private function verifyFinalState(): void
    {
        $remaining = DB::table('files as f')
            ->join('storage_providers as sp', 'sp.id', '=', 'f.storage_provider_id')
            ->where('f.is_folder', true)
            ->whereNull('f.parent_id')
            ->whereNull('f.file_modified_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('files as f2')
                    ->whereColumn('f2.storage_provider_id', 'f.storage_provider_id')
                    ->whereColumn('f2.parent_id', 'f.id');
            })
            ->select('f.storage_provider_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('f.storage_provider_id')
            ->get();

        $this->newLine();
        if ($remaining->isEmpty()) {
            $this->info('<fg=green>OK</> No quedan carpetas raiz con file_modified_at IS NULL por storage.');
        } else {
            $this->warn('Quedan carpetas raiz sin file_modified_at por storage:');
            foreach ($remaining as $r) {
                $this->line("  storage {$r->storage_provider_id}: {$r->cnt}");
            }
        }
    }
}
