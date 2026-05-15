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
                            {--user=1 : User ID para asignar archivos nuevos}';

    protected $description = 'Synchronize files from local storage directories to database';

    public function handle(StorageSyncService $syncService): int
    {
        $userId = (int) $this->option('user');

        if ($this->option('all')) {
            return $this->syncAll($syncService, $userId);
        }

        $storageId = $this->argument('storage_id');
        if (!$storageId) {
            $this->error('Provide a storage_id or use --all.');
            return Command::FAILURE;
        }

        return $this->syncOne((int) $storageId, $syncService, $userId);
    }

    private function syncAll(StorageSyncService $syncService, int $userId): int
    {
        $storages = StorageProvider::where('type', 'local')->where('enabled', true)->get();
        $this->info("Syncing {$storages->count()} local storages...");

        $ok = 0;
        $failed = 0;

        foreach ($storages as $storage) {
            $result = $this->syncOne($storage->id, $syncService, $userId, silent: true);
            $result === Command::SUCCESS ? $ok++ : $failed++;
        }

        $this->info("Done — OK: {$ok}, Failed: {$failed}");
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncOne(int $storageId, StorageSyncService $syncService, int $userId, bool $silent = false): int
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

        $accessible = is_dir($storage->base_path) && is_readable($storage->base_path);

        $storage->update([
            'is_accessible' => $accessible,
            'last_checked_at' => now(),
        ]);

        if (!$accessible) {
            if (!$silent) $this->warn("Path not accessible: {$storage->base_path}");
            else $this->line("  [skip] {$storage->name} — path not accessible");
            return Command::FAILURE;
        }

        if (!$silent) {
            $this->info("Syncing: {$storage->name} (ID: {$storageId})");
        }

        try {
            $start = microtime(true);
            $stats = $syncService->fullSync($storage, $userId);
            $duration = round(microtime(true) - $start, 2);

            if (!$silent) {
                $this->info("  Done — +{$stats['created']} -{$stats['deleted']} in {$duration}s");
            } else {
                $this->line("  [ok]   {$storage->name} — +{$stats['created']} -{$stats['deleted']} ({$duration}s)");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if (!$silent) $this->error("Sync failed: " . $e->getMessage());
            else $this->line("  [err]  {$storage->name} — " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
