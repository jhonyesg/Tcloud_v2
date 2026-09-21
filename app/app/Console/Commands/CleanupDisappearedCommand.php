<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupDisappearedCommand extends Command
{
    protected $signature = 'files:cleanup-disappeared
                            {--dry-run : Solo reporta, no modifica nada (default si no se pasa --apply)}
                            {--apply : Aplica el borrado con snapshot pre-cambio}
                            {--yes : Omite la confirmacion interactiva}
                            {--storage= : Limita a un storage_provider_id concreto (default 5 = 00 Discos)}';

    protected $description = 'Verifica en disco cada archivo del storage; si el path no existe fisicamente, lo borra (con snapshot).';

    public function handle(): int
    {
        $dryRun = !$this->option('apply');
        if ($dryRun) {
            $this->warn('Sin --apply: modo dry-run. La BD no sera modificada.');
        }

        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : 5;

        $storage = StorageProvider::find($storageId);
        if (!$storage) {
            $this->error("Storage {$storageId} no existe.");
            return Command::FAILURE;
        }
        $basePath = rtrim((string) $storage->base_path, '/');
        $this->info("Verificando storage {$storageId} ({$storage->name}) en disco base_path={$basePath}");
        $this->newLine();

        $rows = DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('is_folder', false)
            ->orderBy('id')
            ->get(['id', 'name', 'path', 'size']);

        if ($rows->isEmpty()) {
            $this->info('Storage sin archivos. Nada que verificar.');
            return Command::SUCCESS;
        }

        $stats = ['exists' => 0, 'missing' => 0, 'parent_missing' => 0];
        $missingList = [];

        foreach ($rows as $r) {
            $relPath = ltrim((string) $r->path, '/');
            $fullPath = $basePath . '/' . $relPath;
            if (is_file($fullPath)) {
                $stats['exists']++;
                continue;
            }

            $reason = 'archivo_no_existe';
            $parentDir = dirname($fullPath);
            if (!is_file($fullPath) && !is_dir($parentDir)) {
                $reason = 'padre_no_existe';
            }

            $stats[$reason] = ($stats[$reason] ?? 0) + 1;
            $missingList[] = [
                'id' => (int) $r->id,
                'name' => $r->name,
                'path' => $r->path,
                'full_path' => $fullPath,
                'size' => (int) $r->size,
                'reason' => $reason,
            ];
        }

        $this->table(
            ['storage_id', 'en_disco', 'padre_no_existe', 'archivo_no_existe', 'total'],
            [[
                $storageId,
                number_format($stats['exists']),
                number_format($stats['padre_no_existe'] ?? 0),
                number_format($stats['archivo_no_existe'] ?? 0),
                number_format($rows->count()),
            ]]
        );

        if (empty($missingList)) {
            $this->info('Todos los archivos del storage existen en disco. Nada que borrar.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->line('Muestra de archivos a borrar (primeros 30):');
        foreach (array_slice($missingList, 0, 30) as $m) {
            $size = number_format($m['size'] / 1024, 1) . ' KB';
            $this->line(sprintf('  id=%d %s (%s)', $m['id'], $m['full_path'], $size));
        }
        if (count($missingList) > 30) {
            $this->line('  ... ' . (count($missingList) - 30) . ' archivos mas.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: ninguna modificacion realizada. Use --apply para ejecutar.');
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Se creara snapshot y se borraran " . count($missingList) . " archivos. ¿Continuar?", false)) {
            $this->warn('Cancelado por el operador.');
            return Command::SUCCESS;
        }

        $ts = date('Ymd_His');
        $snapshotTable = "files_disappeared_pre_purge_{$ts}";

        $ids = array_column($missingList, 'id');
        $this->info("Creando snapshot {$snapshotTable} con " . count($ids) . " filas...");

        try {
            DB::statement("
                CREATE TABLE {$snapshotTable} AS
                SELECT * FROM files WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")
            ");
        } catch (\Throwable $e) {
            $this->error('No se pudo crear el snapshot: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $batch) {
            $deleted += DB::table('files')->whereIn('id', $batch)->delete();
        }

        Log::info('files_cleanup_disappeared.completed', [
            'snapshot' => $snapshotTable,
            'storage_id' => $storageId,
            'deleted' => $deleted,
        ]);

        $this->info("Archivos borrados: {$deleted}");
        $this->info("Snapshot persistido: {$snapshotTable}");

        return Command::SUCCESS;
    }
}
