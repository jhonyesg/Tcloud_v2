<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;

class SyncStorage extends Command
{
    protected $signature = 'storage:sync
                            {storage_id? : ID del storage a sincronizar}
                            {--all : Sincronizar todos los storages locales habilitados}
                            {--force : Ignorar mtime y escanear todas las carpetas sin excepcion}
                            {--force-prune : Saltar las guardas de borrado masivo (usar solo si el borrado en disco es real)}
                            {--user=1 : User ID para asignar archivos nuevos}';

    protected $description = 'Synchronize files from local storage directories to database';

    public function handle(StorageSyncService $syncService): int
    {
        $userId = (int) $this->option('user');
        $force  = (bool) $this->option('force');
        $forcePrune = (bool) $this->option('force-prune');

        if ($forcePrune) {
            $this->warn('--force-prune activo: se saltaran las guardas de borrado masivo. La guarda de escaneo no fiable SIGUE vigente.');
        }

        if ($this->option('all')) {
            return $this->syncAll($syncService, $userId, $force, $forcePrune);
        }

        $storageId = $this->argument('storage_id');
        if (!$storageId) {
            $this->error('Provide a storage_id or use --all.');
            return Command::FAILURE;
        }

        return $this->syncOne((int) $storageId, $syncService, $userId, force: $force, forcePrune: $forcePrune);
    }

    private function syncAll(StorageSyncService $syncService, int $userId, bool $force = false, bool $forcePrune = false): int
    {
        $storages = StorageProvider::where('type', 'local')->where('enabled', true)->get();
        $label = $force ? ' (force — mtime skip disabled)' : '';
        $this->info("Syncing {$storages->count()} local storages{$label}...");

        $ok = 0;
        $inaccessible = 0;
        $failed = 0;

        foreach ($storages as $storage) {
            $result = $this->syncOne($storage->id, $syncService, $userId, silent: true, force: $force, forcePrune: $forcePrune);
            if ($result === Command::SUCCESS) {
                $ok++;
            } elseif ($result === 2) { // inaccessible — not a real failure
                $inaccessible++;
            } else {
                $failed++;
            }
        }

        $this->info("Done — OK: {$ok}, Inaccessible: {$inaccessible}, Errors: {$failed}");
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncOne(int $storageId, StorageSyncService $syncService, int $userId, bool $silent = false, bool $force = false, bool $forcePrune = false): int
    {
        $storage = StorageProvider::find($storageId);

        if (!$storage) {
            if (!$silent) $this->error("Storage {$storageId} not found.");
            return Command::FAILURE;
        }

        if ($storage->type !== 'local') {
            if (!$silent) $this->error("Only local storages are supported.");
            return Command::FAILURE;
        }

        // is_dir + is_readable PASAN con un montaje NFS caido: el punto de
        // montaje sigue siendo un directorio local vacio y legible. Por eso se
        // consulta ademas a MountGuard.
        $accessible = is_dir($storage->base_path)
            && is_readable($storage->base_path)
            && app(\App\Services\MountGuard::class)->detachedAncestor($storage->base_path) === null;

        $storage->update([
            'is_accessible' => $accessible,
            'last_checked_at' => now(),
        ]);

        if (!$accessible) {
            if (!$silent) $this->warn("Path not accessible: {$storage->base_path}");
            else $this->line("  [skip] {$storage->name} — path not accessible");
            return 2; // inaccessible — distinct from real error
        }

        if (!$silent) {
            $this->info("Syncing: {$storage->name} (ID: {$storageId})");
        }

        try {
            $start = microtime(true);
            $stats = $syncService->fullSync($storage, $userId, $force, $forcePrune);
            $duration = round(microtime(true) - $start, 2);

            $skippedNote = $stats['skipped'] > 0 ? ", skipped: {$stats['skipped']}" : '';

            if (!$silent) {
                $this->info("  Done — +{$stats['created']} -{$stats['deleted']}{$skippedNote} in {$duration}s");
            } else {
                $this->line("  [ok]   {$storage->name} — +{$stats['created']} -{$stats['deleted']}{$skippedNote} ({$duration}s)");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if (!$silent) $this->error("Sync failed: " . $e->getMessage());
            else $this->line("  [err]  {$storage->name} — " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
