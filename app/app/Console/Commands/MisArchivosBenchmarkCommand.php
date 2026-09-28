<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MisArchivosBenchmarkCommand extends Command
{
    protected $signature = 'mis-archivos:benchmark
                            {--storage=* : Storage IDs a probar (default: todos los local)}
                            {--samples=20 : Numero de carpetas sampleadas por storage}
                            {--depth=2 : Profundidad maxima para samplear}';

    protected $description = 'Compara latencia de scandir() vs query BD para carpetas reales. Diagnostico previo al modo FS-primero.';

    public function handle(): int
    {
        $storageIds = $this->option('storage');
        $storageIds = is_array($storageIds) ? array_map('intval', $storageIds) : [];

        $samples = max(5, (int) $this->option('samples'));
        $depth = max(1, (int) $this->option('depth'));

        if (empty($storageIds)) {
            $storageIds = StorageProvider::where('type', 'local')
                ->where('enabled', true)
                ->pluck('id')
                ->all();
        }

        $this->line("Benchmark scandir() vs query BD");
        $this->line("Storages: " . implode(', ', $storageIds));
        $this->line("Samples por storage: {$samples}, profundidad maxima: {$depth}");
        $this->line('');

        $grandTotals = ['fs' => [], 'db' => [], 'count' => 0];

        foreach ($storageIds as $storageId) {
            $storage = StorageProvider::find($storageId);
            if (!$storage) {
                $this->warn("Storage {$storageId} no existe, saltando.");
                continue;
            }

            $this->line("=== Storage {$storageId} ({$storage->name}) ===");
            $this->line("base_path: {$storage->base_path}");

            $samplePaths = $this->collectSamplePaths($storage->base_path, $samples, $depth);
            if (empty($samplePaths)) {
                $this->warn("  Sin carpetas sampleables, saltando.");
                $this->line('');
                continue;
            }

            $fsTimes = [];
            $dbTimes = [];
            $entryCounts = [];

            foreach ($samplePaths as $subPath) {
                $abs = rtrim($storage->base_path, '/') . '/' . ltrim($subPath, '/');
                if (!is_dir($abs)) {
                    continue;
                }

                $entries = @scandir($abs) ?: [];
                $entryCounts[] = count($entries) - 2;

                $fsStart = hrtime(true);
                $this->scanFilesystem($abs);
                $fsTimes[] = (hrtime(true) - $fsStart) / 1e6;

                $dbStart = hrtime(true);
                $this->queryDatabase($storageId, $subPath);
                $dbTimes[] = (hrtime(true) - $dbStart) / 1e6;
            }

            if (empty($fsTimes)) {
                $this->warn("  Sin muestras validas, saltando.");
                $this->line('');
                continue;
            }

            $avgEntries = (int) (array_sum($entryCounts) / count($entryCounts));
            $this->line("  Carpetas medidas: " . count($fsTimes) . ", entradas promedio: {$avgEntries}");
            $this->line(sprintf("  FS scandir+stat:  min=%6.1fms avg=%6.1fms max=%6.1fms p95=%6.1fms",
                min($fsTimes), array_sum($fsTimes)/count($fsTimes), max($fsTimes), $this->percentile($fsTimes, 95)));
            $this->line(sprintf("  BD query:        min=%6.1fms avg=%6.1fms max=%6.1fms p95=%6.1fms",
                min($dbTimes), array_sum($dbTimes)/count($dbTimes), max($dbTimes), $this->percentile($dbTimes, 95)));

            $fsAvg = array_sum($fsTimes)/count($fsTimes);
            $dbAvg = array_sum($dbTimes)/count($dbTimes);
            $ratio = $dbAvg > 0 ? $fsAvg / $dbAvg : 0;
            $verdict = $ratio < 1 ? 'FS GANA' : 'BD GANA';
            $this->line(sprintf("  Ratio FS/BD: %.2fx  →  %s", $ratio, $verdict));
            $this->line('');

            $grandTotals['fs'] = array_merge($grandTotals['fs'], $fsTimes);
            $grandTotals['db'] = array_merge($grandTotals['db'], $dbTimes);
            $grandTotals['count'] += count($fsTimes);
        }

        if ($grandTotals['count'] > 0) {
            $this->line('=== TOTAL ===');
            $this->line(sprintf("FS scandir+stat: avg=%6.1fms p95=%6.1fms",
                array_sum($grandTotals['fs'])/count($grandTotals['fs']),
                $this->percentile($grandTotals['fs'], 95)));
            $this->line(sprintf("BD query:       avg=%6.1fms p95=%6.1fms",
                array_sum($grandTotals['db'])/count($grandTotals['db']),
                $this->percentile($grandTotals['db'], 95)));
        }

        $this->line('');
        $this->info('Interpretacion:');
        $this->line('  - FS < 100 ms y ratio < 1.0  →  modo FS-primero viable, sin paginacion forzada.');
        $this->line('  - FS 100-300 ms              →  modo FS-primero viable con paginacion limit=500.');
        $this->line('  - FS > 500 ms o ratio > 2.0  →  reconsiderar plan, NFS remoto o carpetas grandes.');

        return self::SUCCESS;
    }

    private function collectSamplePaths(string $basePath, int $samples, int $depth): array
    {
        $baseReal = realpath($basePath);
        if (!$baseReal || !is_dir($baseReal)) {
            return [];
        }

        $paths = [''];
        $this->collectRecursive($baseReal, $baseReal, 1, $depth, $paths);

        shuffle($paths);
        return array_slice($paths, 0, $samples);
    }

    private function collectRecursive(string $base, string $current, int $level, int $max, array &$paths): void
    {
        if ($level > $max || count($paths) > 500) {
            return;
        }
        $entries = @scandir($current) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $abs = $current . '/' . $name;
            if (!is_dir($abs)) {
                continue;
            }
            $rel = ltrim(substr($abs, strlen($base)), '/');
            $paths[] = $rel;
            $this->collectRecursive($base, $abs, $level + 1, $max, $paths);
        }
    }

    private function scanFilesystem(string $abs): void
    {
        $entries = @scandir($abs) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            $entryAbs = $abs . '/' . $name;
            @stat($entryAbs);
        }
    }

    private function queryDatabase(int $storageId, string $subPath): array
    {
        return DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('path', 'LIKE', $subPath === '' ? '%' : ($subPath . '%'))
            ->orderBy('is_folder', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get()
            ->toArray();
    }

    private function percentile(array $values, int $p): float
    {
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        return $values[max(0, min($idx, count($values) - 1))];
    }
}
