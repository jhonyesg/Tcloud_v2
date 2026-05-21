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
                            {--user=1 : User ID para asignar archivos nuevos}';

    protected $description = 'Synchronize files from local storage directories to database';

    public function handle(StorageSyncService $syncService): int
    {
        $userId = (int) $this->option('user');
        $force  = (bool) $this->option('force');

        if ($this->option('all')) {
            return $this->syncAll($syncService, $userId, $force);
        }

        $storageId = $this->argument('storage_id');
        if (!$storageId) {
            $this->error('Provide a storage_id or use --all.');
            return Command::FAILURE;
        }

        return $this->syncOne((int) $storageId, $syncService, $userId, force: $force);
    }

    private function syncAll(StorageSyncService $syncService, int $userId, bool $force = false): int
    {
        $storages = StorageProvider::where('type', 'local')->where('enabled', true)->get();
        $label = $force ? ' (force — mtime skip disabled)' : '';
        $this->info("Syncing {$storages->count()} local storages{$label}...");

        $ok = 0;
        $inaccessible = 0;
        $failed = 0;

        foreach ($storages as $storage) {
            $result = $this->syncOne($storage->id, $syncService, $userId, silent: true, force: $force);
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

    private function syncOne(int $storageId, StorageSyncService $syncService, int $userId, bool $silent = false, bool $force = false): int
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
            return 2; // inaccessible — distinct from real error
        }

        if (!$silent) {
            $this->info("Syncing: {$storage->name} (ID: {$storageId})");
        }

        try {
            $start = microtime(true);
            $stats = $syncService->fullSync($storage, $userId, $force);
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
