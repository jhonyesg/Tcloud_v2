<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\MisArchivos\FilesystemDbMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MatchFilesystemToDatabaseCommand extends Command
{
    protected $signature = 'mis-archivos:match-fs-db
                            {--storage=* : Limitar a uno o mas storage_provider_id}
                            {--mode=hot_warm : hot_only | hot_warm | hot_warm_cold}';

    protected $description = 'Reconcilia filesystem con la tabla files para mantener la BD caliente con metadata operativa.';

    public function handle(FilesystemDbMatcher $matcher): int
    {
        $mode = (string) $this->option('mode');
        if (!in_array($mode, ['hot_only', 'hot_warm', 'hot_warm_cold'], true)) {
            $this->error("Modo invalido: {$mode}. Usa hot_only | hot_warm | hot_warm_cold.");
            return self::FAILURE;
        }

        $storageFilter = $this->option('storage');
        $storageFilter = is_array($storageFilter) ? array_map('intval', $storageFilter) : [];

        $query = StorageProvider::where('enabled', true)->where('type', 'local');
        if (!empty($storageFilter)) {
            $query->whereIn('id', $storageFilter);
        }
        $storages = $query->get();

        if ($storages->isEmpty()) {
            $this->warn('[skip] no hay storages local+enabled para procesar.');
            return self::SUCCESS;
        }

        $totals = ['scanned' => 0, 'created' => 0, 'updated' => 0, 'missing_marked' => 0, 'missing_purged' => 0, 'skipped' => 0];

        foreach ($storages as $storage) {
            $lockKey = "mis_archivos:matcher:lock:{$storage->id}";
            $lock = Cache::lock($lockKey, 600);
            if (!$lock->get()) {
                $this->line("  [skip-locked] {$storage->id} ({$storage->name})");
                $totals['skipped']++;
                continue;
            }

            try {
                $this->line("Procesando storage {$storage->id} ({$storage->name}) modo={$mode}...");
                $stats = $matcher->matchStorage($storage->id, $mode);
                foreach (['scanned', 'created', 'updated', 'missing_marked', 'missing_purged'] as $k) {
                    $totals[$k] += $stats[$k] ?? 0;
                }
                $this->line(sprintf(
                    "  [ok] %s — scanned=%d created=%d updated=%d missing_marked=%d missing_purged=%d",
                    $storage->name,
                    $stats['scanned'] ?? 0,
                    $stats['created'] ?? 0,
                    $stats['updated'] ?? 0,
                    $stats['missing_marked'] ?? 0,
                    $stats['missing_purged'] ?? 0,
                ));
            } catch (\Throwable $e) {
                Log::error('mis_archivos.matcher_failed', [
                    'storage_id' => $storage->id,
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  [err] {$storage->name}: {$e->getMessage()}");
                $totals['skipped']++;
            } finally {
                $lock->release();
            }
        }

        $this->line('');
        $this->info(sprintf(
            'TOTAL: scanned=%d created=%d updated=%d missing_marked=%d missing_purged=%d skipped=%d',
            $totals['scanned'],
            $totals['created'],
            $totals['updated'],
            $totals['missing_marked'],
            $totals['missing_purged'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
