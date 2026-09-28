<?php

namespace App\Console\Commands;

use App\Services\FileBreadcrumbIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FilesRepairBreadcrumbCyclesCommand extends Command
{
    protected $signature = 'files:repair-breadcrumb-cycles
                            {--apply : ejecuta la reparación en lugar de solo listar}
                            {--storage=* : limita a uno o más storage_provider_id}';

    protected $description = 'Detecta y repara carpetas con breadcrumb duplicado consecutivo';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $storageFilter = $this->option('storage');
        $storageFilter = is_array($storageFilter) ? array_map('intval', $storageFilter) : [];

        $cycles = FileBreadcrumbIntegrityService::findAllCycles();

        if (!empty($storageFilter)) {
            $cycles = $cycles->filter(fn($r) => in_array((int) $r->storage_provider_id, $storageFilter, true));
        }

        $legit = $cycles->filter(function ($r) {
            $row = DB::table('files')->where('id', $r->id)->first();
            if (!$row) {
                return false;
            }
            return str_contains((string) $row->path, '/lib/lib')
                && substr_count((string) $row->path, '/') >= 3;
        });

        $pathological = $cycles->reject(fn($r) => $legit->contains('id', $r->id));

        $byStorage = $pathological->groupBy('storage_provider_id');

        if ($byStorage->isEmpty()) {
            $this->info('[OK] No se encontraron carpetas con breadcrumb duplicado patológico.');
            return self::SUCCESS;
        }

        $this->line($apply ? '[APPLY]' : '[DRY-RUN]');
        $this->line('');

        $totalRepaired = 0;
        $totalPending = 0;

        foreach ($byStorage as $storageId => $items) {
            $storage = DB::table('storage_providers')->where('id', $storageId)->first();
            $name = $storage->name ?? "id={$storageId}";

            $this->line("Storage {$storageId} ({$name}):");

            foreach ($items as $item) {
                $parentName = DB::table('files')->where('id', $item->parent_id)->value('name');

                if ($apply) {
                    $result = FileBreadcrumbIntegrityService::repairInPlace((int) $item->id);
                    if ($result['repaired']) {
                        $this->line("  - Folder id={$item->id} \"{$item->name}\" (parent={$item->parent_id}) → re-parado a {$result['new_parent_id']} [OK]");
                        $totalRepaired++;
                        Cache::increment("folder_gen:{$storageId}:{$result['new_parent_id']}");
                    } else {
                        $reason = $result['reason'] ?? 'unknown';
                        $this->line("  - Folder id={$item->id} \"{$item->name}\" → NO reparado (reason={$reason})");
                        $totalPending++;
                    }
                } else {
                    $this->line("  - Folder id={$item->id} \"{$item->name}\" parent={$item->parent_id} (parent_name={$parentName}) → re-parent sugerido");
                    $totalPending++;
                }
            }
            $this->line('');
        }

        if ($legit->isNotEmpty()) {
            $this->line("Casos legítimos omitidos (no patológicos): " . $legit->count());
            $this->line('');
        }

        if ($apply) {
            $this->info("Resumen: {$totalRepaired} reparados, {$totalPending} pendientes.");
            return $totalPending === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Resumen: {$totalPending} carpetas a reparar. Ejecutar con --apply para proceder.");
        return $totalPending > 0 ? self::FAILURE : self::SUCCESS;
    }
}
