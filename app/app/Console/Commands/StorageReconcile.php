<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\StorageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliación paced al detectar un remonte de disco externo.
 *
 * El watchdog `storage:health` despacha este comando cuando un storage
 * `kind='external` pasa de is_accessible=false a true. La tarea:
 *
 *  1. Toma el lock distribuido `sync:storage:{id}` (no bloqueante).
 *  2. Si no lo obtiene, devuelve skipped_locked y termina.
 *  3. Si lo obtiene, ejecuta fullSync() con force=true para re-verificar
 *     toda la jerarquía, no solo carpetas con mtime reciente.
 *  4. fullSync() ya internamente respeta chunkById(50), sin get() masivo.
 *
 * Pacing: entre cada pasada del chunk se hace `sleep($pace)` para no
 * saturar el server. La bandera `--no-pacing` la salta (uso de operador).
 *
 * `--storage={id}` opcional: si se omite, procesa todos los external
 * accesibles en este momento (uso batch).
 */
class StorageReconcile extends Command
{
    protected $signature = 'storage:reconcile
                            {--storage= : Limitar a un storage_provider_id}
                            {--no-pacing : Saltarse sleeps entre chunks}
                            {--user=1 : User ID para asignar archivos nuevos}';

    protected $description = 'Reconciliación paced de un storage remontado, pensada para correr en background.';

    public function handle(StorageSyncService $syncService): int
    {
        $userId = (int) $this->option('user');
        $noPacing = (bool) $this->option('no-pacing');
        $storageOpt = $this->option('storage');

        $query = StorageProvider::where('enabled', true)
            ->where('is_accessible', true)
            ->where('kind', 'external');

        if ($storageOpt !== null) {
            $query->where('id', (int) $storageOpt);
        }

        $storages = $query->get();

        if ($storages->isEmpty()) {
            $this->line('[skip] no hay storages external accesibles para reconciliar');
            return Command::SUCCESS;
        }

        $stats = ['processed' => 0, 'skipped_locked' => 0, 'failed' => 0, 'reconciled' => 0];

        foreach ($storages as $storage) {
            $stats['processed']++;
            $result = $this->reconcileOne($storage, $userId, $noPacing, $syncService);

            match ($result) {
                'success' => $stats['reconciled']++,
                'locked' => $stats['skipped_locked']++,
                default => $stats['failed']++,
            };
        }

        $this->info(sprintf(
            'processed=%d reconciled=%d skipped_locked=%d failed=%d',
            $stats['processed'],
            $stats['reconciled'],
            $stats['skipped_locked'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function reconcileOne(
        StorageProvider $storage,
        int $userId,
        bool $noPacing,
        StorageSyncService $syncService
    ): string {
        $lockTtl = (int) config('storage_sync.reconcile.lock_ttl_seconds', 3600);
        $lock = Cache::lock("sync:storage:{$storage->id}", $lockTtl);

        if (!$lock->get()) {
            Log::info('storage_reconcile.skipped_locked', [
                'storage_id' => $storage->id,
                'name' => $storage->name,
            ]);
            $this->line("  [skip-locked] {$storage->name}");
            return 'locked';
        }

        try {
            $start = microtime(true);

            if (!$noPacing) {
                // Refleja el pace_seconds entre rondas de fullSync. El pacing
                // interno de fullSync() (chunkById + sleep) ya esta aplicado;
                // este sleep es entre storages cuando se procesan varios.
                $pace = (int) config('storage_sync.reconcile.pace_seconds', 2);
                if ($pace > 0) {
                    sleep($pace);
                }
            }

            $stats = $syncService->fullSync($storage, $userId, force: true, forcePrune: false);

            $duration = round(microtime(true) - $start, 2);
            $note = ($stats['skipped'] ?? 0) > 0
                ? ", skipped: {$stats['skipped']}"
                : '';

            Log::info('storage_reconcile.completed', [
                'storage_id' => $storage->id,
                'name' => $storage->name,
                'duration_s' => $duration,
                'created' => $stats['created'] ?? 0,
                'updated' => $stats['updated'] ?? 0,
                'deleted' => $stats['deleted'] ?? 0,
                'skipped' => $stats['skipped'] ?? 0,
            ]);

            $this->line("  [ok]   {$storage->name} — +{$stats['created']} ~{$stats['updated']} -{$stats['deleted']}{$note} ({$duration}s)");

            return 'success';
        } catch (\Throwable $e) {
            Log::error('storage_reconcile.failed', [
                'storage_id' => $storage->id,
                'name' => $storage->name,
                'error' => $e->getMessage(),
            ]);
            $this->error("  [err]  {$storage->name} — " . $e->getMessage());
            return 'failed';
        } finally {
            $lock->release();
        }
    }
}