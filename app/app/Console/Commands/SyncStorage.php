<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;

class SyncStorage extends Command
{
    protected $signature = 'storage:sync {storage_id : The ID of the storage to sync} {--user= : User ID to assign files to}';

    protected $description = 'Synchronize files from storage directory to database';

    public function handle(StorageSyncService $syncService): int
    {
        $storageId = (int) $this->argument('storage_id');
        $userId = (int) ($this->option('user') ?? 1);

        $storage = StorageProvider::find($storageId);
        if (!$storage) {
            $this->error("Storage with ID {$storageId} not found.");
            return Command::FAILURE;
        }

        if ($storage->type !== 'local') {
            $this->error("Only local storage sync is supported currently.");
            return Command::FAILURE;
        }

        $basePath = $storage->base_path;
        if (!is_dir($basePath)) {
            $this->error("Storage base path does not exist: {$basePath}");
            return Command::FAILURE;
        }

        $this->info("Starting sync for storage: {$storage->name} (ID: {$storageId})");
        $this->info("Base path: {$basePath}");
        $this->newLine();

        $startTime = microtime(true);

        try {
            $files = $syncService->syncRootFolder($storage, $userId);

            $duration = round(microtime(true) - $startTime, 2);

            $this->info("Sync completed successfully!");
            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Files synced', count($files)],
                    ['Duration', "{$duration}s"],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
