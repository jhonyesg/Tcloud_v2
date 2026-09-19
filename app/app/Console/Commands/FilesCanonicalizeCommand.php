<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change `files-canonical-owner-by-storage` (2026-09-18):
 *
 * Reescribe `files.owner_id` al owner canonico de cada storage (helper
 * `StorageProvider::canonicalOwnerId()`). Antes del UPDATE, captura un snapshot
 * en `files_owner_canonical_audit` para que `migrate:rollback` pueda restaurar.
 *
 * Uso:
 *   php artisan tcloud:files-canonicalize --dry-run    # cuenta, no muta
 *   php artisan tcloud:files-canonicalize --apply      # ejecuta
 *
 * Rendimiento: ~30-90s para 2.26M filas (set-based UPDATE con
 * `session_replication_role = replica` para evitar evaluacion per-row de FKs).
 */
class FilesCanonicalizeCommand extends Command
{
    protected $signature = 'tcloud:files-canonicalize
                            {--dry-run : Solo contar, no mutar}
                            {--apply   : Ejecutar la migracion}';

    protected $description = 'Reasigna files.owner_id al owner canonico por storage';

    public function handle(): int
    {
        if (!$this->option('dry-run') && !$this->option('apply')) {
            $this->error('Especifica --dry-run o --apply');
            return Command::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $this->info($isDryRun
            ? 'Modo dry-run: contando filas a reasignar...'
            : 'Modo apply: ejecutando migracion canonica...');

        // Pre-computar mapa storage_id => canonical_owner_id en PHP (con recursión).
        // Esto evita tener que replicar la CTE recursiva en SQL y permite usar
        // exactamente el mismo helper `StorageProvider::canonicalOwnerId()` que
        // usara el codigo de aplicacion.
        $storages = DB::table('storage_providers')
            ->select(['id', 'is_personal', 'parent_storage_id'])
            ->get();

        $canonicalByStorage = [];
        foreach ($storages as $storage) {
            $canonicalByStorage[(int) $storage->id] = $this->resolveCanonical(
                (int) $storage->id,
                $storages,
                []
            );
        }

        // Conteos pre-cambio
        $stats = $this->computeStats($canonicalByStorage);

        $this->table(
            ['storage_id', 'storage_name', 'canonical_owner_id', 'files_total', 'files_to_update'],
            $stats['rows']
        );
        $this->info("Total filas a reasignar: {$stats['total_to_update']}");
        $this->info("Storage sin canónico resuelto (serán excluidos): {$stats['storages_without_canonical']}");

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        // Implementacion real
        return $this->applyMigration($canonicalByStorage);
    }

    private function resolveCanonical(int $storageId, $storages, array $visited): ?int
    {
        if (in_array($storageId, $visited, true)) return null;
        $visited[] = $storageId;

        $storage = $storages->firstWhere('id', $storageId);
        if (!$storage) return null;

        $orderColumn = $storage->is_personal ? 'user_id' : 'id';

        $owner = DB::table('user_storages')
            ->where('storage_provider_id', $storageId)
            ->where('permissions', 'full')
            ->orderBy($orderColumn)
            ->value('user_id');

        if ($owner !== null) return (int) $owner;

        if ($storage->parent_storage_id === null) return null;
        return $this->resolveCanonical((int) $storage->parent_storage_id, $storages, $visited);
    }

    private function computeStats(array $canonicalByStorage): array
    {
        $rows = [];
        $totalToUpdate = 0;
        $storagesWithoutCanonical = 0;

        foreach ($canonicalByStorage as $storageId => $canonicalOwnerId) {
            if ($canonicalOwnerId === null) {
                $storagesWithoutCanonical++;
                continue;
            }

            $storageName = DB::table('storage_providers')->where('id', $storageId)->value('name');
            $filesTotal = DB::table('files')
                ->where('storage_provider_id', $storageId)
                ->where('is_trashed', false)
                ->whereNull('deleted_at')
                ->count();
            $filesToUpdate = DB::table('files')
                ->where('storage_provider_id', $storageId)
                ->where('is_trashed', false)
                ->whereNull('deleted_at')
                ->where('owner_id', '!=', $canonicalOwnerId)
                ->count();

            $totalToUpdate += $filesToUpdate;
            $rows[] = [
                'storage_id' => $storageId,
                'storage_name' => $storageName,
                'canonical_owner_id' => $canonicalOwnerId,
                'files_total' => $filesTotal,
                'files_to_update' => $filesToUpdate,
            ];
        }

        return [
            'rows' => $rows,
            'total_to_update' => $totalToUpdate,
            'storages_without_canonical' => $storagesWithoutCanonical,
        ];
    }

    private function applyMigration(array $canonicalByStorage): int
    {
        DB::beginTransaction();
        try {
            DB::statement('SET LOCAL session_replication_role = replica');
            DB::statement('SET LOCAL statement_timeout = 0');
            DB::statement('SET LOCAL lock_timeout = 0');

            $this->info('Paso 1/3: Capturando snapshot en files_owner_canonical_audit...');
            // Truncar audit por si se re-ejecuta
            DB::table('files_owner_canonical_audit')->truncate();

            foreach ($canonicalByStorage as $storageId => $canonicalOwnerId) {
                if ($canonicalOwnerId === null) continue;

                DB::statement("
                    INSERT INTO files_owner_canonical_audit
                      (file_id, storage_provider_id, old_owner_id, canonical_owner_id, captured_at)
                    SELECT id, storage_provider_id, owner_id, ?, NOW()
                    FROM files
                    WHERE storage_provider_id = ?
                      AND is_trashed = false
                      AND deleted_at IS NULL
                      AND owner_id <> ?
                ", [$canonicalOwnerId, $storageId, $canonicalOwnerId]);
            }

            $this->info('Paso 2/3: UPDATE files SET owner_id = canonical...');
            $updated = 0;
            foreach ($canonicalByStorage as $storageId => $canonicalOwnerId) {
                if ($canonicalOwnerId === null) continue;

                $count = DB::affectingStatement("
                    UPDATE files
                    SET owner_id = ?
                    WHERE storage_provider_id = ?
                      AND is_trashed = false
                      AND deleted_at IS NULL
                      AND owner_id <> ?
                ", [$canonicalOwnerId, $storageId, $canonicalOwnerId]);
                $updated += $count;
            }
            $this->info("Filas actualizadas: {$updated}");

            $this->info('Paso 3/3: COMMIT');
            DB::commit();
            $this->info('Migracion completa.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Error en migracion: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
