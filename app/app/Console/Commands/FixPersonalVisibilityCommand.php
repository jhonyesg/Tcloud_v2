<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixPersonalVisibilityCommand extends Command
{
    protected $signature = 'user-storages:fix-personal-visibility
                            {--dry-run : Solo reporta, no modifica nada (default si no se pasa --apply)}
                            {--apply : Aplica los cambios propuestos}
                            {--yes : Omite la confirmacion interactiva}
                            {--user= : Limita a un storage_provider_id concreto}';

    protected $description = 'Saca a usuarios no-canonicos de los storages personales. Deja solo al dueno segun el segmento de base_path.';

    public function handle(): int
    {
        if (!$this->option('apply')) {
            $this->warn('Sin --apply: modo dry-run. La BD no sera modificada.');
            $dryRun = true;
        } else {
            $dryRun = false;
        }

        $storageIdFilter = $this->option('user') !== null ? (int) $this->option('user') : null;

        $storages = $this->loadPersonalStorages($storageIdFilter);

        if ($storages->isEmpty()) {
            $this->info('No hay storages personales para procesar.');
            return Command::SUCCESS;
        }

        $this->info("Storages personales a auditar: {$storages->count()}");
        $this->newLine();

        $reportRows = [];
        $totals = [
            'storages' => 0,
            'rows_kept' => 0,
            'rows_to_drop' => 0,
            'unresolved' => 0,
        ];

        foreach ($storages as $storage) {
            $row = $this->auditStorage($storage);
            $reportRows[] = $row;
            $totals['storages']++;
            $totals['rows_kept'] += $row['keep'];
            $totals['rows_to_drop'] += $row['drop'];
            if ($row['status'] === 'unresolved') {
                $totals['unresolved']++;
            }
        }

        $this->table(
            ['storage_id', 'nombre', 'dueno_canonico', 'rows_actuales', 'conservar', 'eliminar', 'estado'],
            array_map(fn ($r) => [
                $r['storage_id'],
                $r['name'],
                $r['canonical'] ?? '[no resuelto]',
                $r['current'],
                $r['keep'],
                $r['drop'],
                $r['status'],
            ], $reportRows)
        );

        $this->newLine();
        $this->info('Resumen:');
        $this->line("  storages personales:       {$totals['storages']}");
        $this->line("  user_storages a conservar: {$totals['rows_kept']}");
        $this->line("  user_storages a eliminar:  {$totals['rows_to_drop']}");
        if ($totals['unresolved'] > 0) {
            $this->warn("  con canonico sin resolver: {$totals['unresolved']} (se omiten del borrado)");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: ninguna modificacion realizada. Use --apply para ejecutar.');
            return Command::SUCCESS;
        }

        if ($totals['rows_to_drop'] === 0) {
            $this->info('Nada que eliminar. Verificacion final:');
            $this->verifyFinalState();
            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Se eliminaran {$totals['rows_to_drop']} filas de user_storages. ¿Continuar?", false)) {
            $this->warn('Cancelado por el operador.');
            return Command::SUCCESS;
        }

        $deleted = 0;
        $errors = 0;

        foreach ($storages as $storage) {
            $row = $this->auditStorage($storage);
            if ($row['status'] !== 'resolved' || $row['drop'] === 0) {
                continue;
            }

            try {
                $affected = $this->deleteNonCanonical($storage, $row['canonical']);
                $deleted += $affected;
                Log::info('user_storages.fix_personal_visibility', [
                    'storage_id' => $storage->id,
                    'kept_user' => $row['canonical'],
                    'deleted_rows' => $affected,
                ]);
            } catch (\Throwable $e) {
                $errors++;
                Log::error('user_storages.fix_personal_visibility_error', [
                    'storage_id' => $storage->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  storage {$storage->id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Filas eliminadas: {$deleted}");
        if ($errors > 0) {
            $this->warn("Errores: {$errors}");
        }

        $this->verifyFinalState();

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function loadPersonalStorages(?int $storageId): \Illuminate\Support\Collection
    {
        $query = StorageProvider::query()
            ->where('is_personal', true)
            ->orderBy('id');

        if ($storageId !== null) {
            $query->where('id', $storageId);
        }

        return $query->get();
    }

    private function auditStorage(StorageProvider $storage): array
    {
        $canonical = $storage->personalCanonicalUsername();
        $rows = UserStorage::where('storage_provider_id', $storage->id)
            ->join('users', 'users.id', '=', 'user_storages.user_id')
            ->orderBy('user_storages.id')
            ->get(['user_storages.id', 'user_storages.user_id', 'users.username', 'user_storages.permissions']);

        $current = $rows->count();

        if ($canonical === null) {
            return [
                'storage_id' => $storage->id,
                'name' => $storage->name,
                'base_path' => $storage->base_path,
                'canonical' => null,
                'current' => $current,
                'keep' => $current > 0 ? 1 : 0,
                'drop' => 0,
                'status' => 'unresolved',
                'rows' => $rows->toArray(),
            ];
        }

        $canonicalExists = User::where('username', $canonical)->exists();

        if (!$canonicalExists) {
            return [
                'storage_id' => $storage->id,
                'name' => $storage->name,
                'base_path' => $storage->base_path,
                'canonical' => $canonical,
                'current' => $current,
                'keep' => $current > 0 ? 1 : 0,
                'drop' => 0,
                'status' => 'unresolved',
                'rows' => $rows->toArray(),
            ];
        }

        $keepRows = $rows->where('username', $canonical);
        $dropRows = $rows->where('username', '!=', $canonical);

        return [
            'storage_id' => $storage->id,
            'name' => $storage->name,
            'base_path' => $storage->base_path,
            'canonical' => $canonical,
            'current' => $current,
            'keep' => $keepRows->count() > 0 ? 1 : 0,
            'drop' => $dropRows->count(),
            'status' => 'resolved',
            'rows' => $rows->toArray(),
        ];
    }

    private function deleteNonCanonical(StorageProvider $storage, string $canonicalUsername): int
    {
        return DB::transaction(function () use ($storage, $canonicalUsername) {
            return UserStorage::where('storage_provider_id', $storage->id)
                ->whereNotIn('user_id', function ($q) use ($canonicalUsername) {
                    $q->select('id')->from('users')->where('username', $canonicalUsername);
                })
                ->delete();
        });
    }

    private function verifyFinalState(): void
    {
        $violators = DB::table('user_storages')
            ->join('storage_providers', 'storage_providers.id', '=', 'user_storages.storage_provider_id')
            ->where('storage_providers.is_personal', true)
            ->select(
                'storage_providers.id as storage_id',
                'storage_providers.name',
                'storage_providers.base_path',
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('storage_providers.id', 'storage_providers.name', 'storage_providers.base_path')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->newLine();
        if ($violators->isEmpty()) {
            $this->info('<fg=green>OK</> Ningun storage personal tiene mas de 1 user_storages.');
        } else {
            $this->warn('Quedan storages personales con multiples user_storages (puede ser intencional):');
            foreach ($violators as $v) {
                $this->line("  storage {$v->storage_id} ({$v->name}): {$v->cnt} filas");
            }
        }
    }
}
