<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remapeo (T, change `migrate-storage-disco-g-to-h`).
 *
 * Mueve TODOS los storage_providers cuyo base_path contiene `Disco_G` para que
 * apunten a `Disco_H`. Tambien:
 *   - Merge storages con mismo path (DD4) — ids duplicados a mano sobre el mismo
 *     subtree; conserva el id con mas archivos y reasigna el resto.
 *   - Reescribe `transcriptions.source_absolute_path` con el disco nuevo en / Disco_H.
 *
 * Patron de seguridad:
 *
 *   - Snapshot pre-mutacion: tabla `storage_providers_remap_pre_<ts>` con una
 *     copia identica de cada storage_provider modificado.
 *   - Tag unico `hsr_<hex>` en tablas temporales para cleanup defensivo.
 *   - Dry-run por defecto. `--apply` requiere `--yes` (sin el yes, aborta).
 *   - Invalida caches post-apply:
 *       * `transcriptor.scope.inherited.{id}` para cada id afectado.
 *       * `transcriptor.scope.{id}` (resolveRootIdFor).
 *       * `folder_gen:{id}:null` para cada id afectado (Mis Archivos).
 *       * `admin:storages:index`.
 *       * `CacheEpoch::bump()` (Avisos cobertura).
 *
 * El comando es BD-only. La copia fisica de archivos (rsync del NFS host) la
 * hace el operador fuera de este comando. Coordinacion: aplicar la copia FISICA
 * ANTES de correr `--apply`, o el resultado sera 404.
 */
class StorageRemapDisk extends Command
{
    protected $signature = 'storage:remap-disk
                            {--from=Disco_G : Disco origen (default Disco_G)}
                            {--to=Disco_H : Disco destino (default Disco_H)}
                            {--apply : Ejecuta los cambios (default dry-run)}
                            {--yes : Skip confirmacion en --apply}';

    protected $description = 'Remap base_path de storages de Disco_G a Disco_H + merge overlapping + reescribe transcriptions.source_absolute_path.';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $apply = (bool) $this->option('apply');
        $yes = (bool) $this->option('yes');

        if ($from === $to) {
            $this->error('--from y --to no pueden ser iguales.');
            return Command::FAILURE;
        }

        $tag = 'hsr_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $snapshotTable = "storage_providers_remap_pre_" . date('Ymd_His');

        $this->info("== storage:remap-disk ({$from} -> {$to}) ==");
        $this->line("mode: " . ($apply ? 'APPLY' : 'dry-run'));
        $this->line("tag: {$tag}");
        $this->line("snapshot table (apply only): {$snapshotTable}");
        $this->newLine();

        $fromToken = "/{$from}/";
        $toToken = "/{$to}/";

        $storages = StorageProvider::query()
            ->where('base_path', 'LIKE', "%{$fromToken}%")
            ->orderBy('parent_storage_id')
            ->orderBy('id')
            ->get();

        if ($storages->isEmpty()) {
            $this->warn("No hay storages con '{$fromToken}' en base_path. Nada que hacer.");
            return Command::SUCCESS;
        }

        $this->info("Storages a remappear ({$storages->count()}):");
        $this->table(
            ['ID', 'Nombre', 'parent', 'base_path (nuevo)', 'files', 'tx'],
            $storages->map(function ($s) use ($to, $from, $toToken, $fromToken) {
                $newPath = str_replace($fromToken, $toToken, $s->base_path);
                $files = DB::table('files')->where('storage_provider_id', $s->id)->where('is_folder', false)->count();
                $tx = DB::table('transcriptions')
                    ->whereIn('file_id', DB::table('files')->where('storage_provider_id', $s->id)->select('id'))
                    ->count();
                return [(int) $s->id, $s->name, (int) ($s->parent_storage_id ?? 0), $newPath, $files, $tx];
            })->toArray()
        );

        $byPath = [];
        foreach ($storages as $s) {
            $newPath = str_replace($fromToken, $toToken, $s->base_path);
            $byPath[$newPath][] = $s;
        }

        $mergePairs = [];
        foreach ($byPath as $newPath => $group) {
            if (count($group) > 1) {
                usort($group, function ($a, $b) {
                    $af = DB::table('files')->where('storage_provider_id', $a->id)->where('is_folder', false)->count();
                    $bf = DB::table('files')->where('storage_provider_id', $b->id)->where('is_folder', false)->count();
                    return $bf <=> $af;
                });
                $keep = $group[0];
                $drop = array_slice($group, 1);
                $mergePairs[] = ['keep' => $keep, 'drop' => $drop, 'new_path' => $newPath];
            }
        }

        if (!empty($mergePairs)) {
            $this->newLine();
            $this->warn('MERGES detectados (paths nuevos iguales):');
            foreach ($mergePairs as $pair) {
                $this->line("  KEEP #{$pair['keep']->id} '{$pair['keep']->name}' -> {$pair['new_path']}");
                foreach ($pair['drop'] as $d) {
                    $files = DB::table('files')->where('storage_provider_id', $d->id)->where('is_folder', false)->count();
                    $this->line("    DROP #{$d->id} '{$d->name}' ({$files} files, parent={$d->parent_storage_id})");
                }
            }
        } else {
            $this->newLine();
            $this->line('No se detectan paths nuevos duplicados entre los storages a migrar.');
        }

        $txCount = DB::table('transcriptions')
            ->where('source_absolute_path', 'LIKE', "%{$fromToken}%")
            ->count();
        $this->newLine();
        $this->info("Transcripciones con '{$from}' en source_absolute_path: {$txCount}");

        $cacheToInvalidate = $storages->pluck('id')->unique()->values()->all();
        $cacheToInvalidate = array_merge(
            $cacheToInvalidate,
            collect($mergePairs)->pluck('keep.id')->unique()->values()->all()
        );
        $cacheToInvalidate = array_values(array_unique($cacheToInvalidate));

        $this->line('Caches a limpiar (APPLY only):');
        $this->line('  - transcriptor.scope.inherited.{id} for ids: ' . implode(',', $cacheToInvalidate));
        $this->line('  - transcriptor.scope.{id} for ids: ' . implode(',', $cacheToInvalidate));
        $this->line('  - folder_gen:{id}:null for ids: ' . implode(',', $cacheToInvalidate));
        $this->line('  - admin:storages:index');
        $this->line('  - CacheEpoch::bump()');

        if (!$apply) {
            $this->newLine();
            $this->warn('DRY-RUN. Para aplicar: --apply --yes');
            return Command::SUCCESS;
        }

        if (!$yes) {
            $this->newLine();
            if (!$this->confirm('Proceder con el remapeo y los merges? Los snapshots se crean antes de cualquier cambio.', true)) {
                $this->warn('Abortado por usuario.');
                return Command::FAILURE;
            }
        }

        DB::transaction(function () use (
            $storages,
            $mergePairs,
            $fromToken,
            $toToken,
            $snapshotTable,
            $tag,
            $cacheToInvalidate
        ) {
            $affectedIds = $storages->pluck('id')->unique()->values()->all();
            foreach ($mergePairs as $pair) {
                foreach ($pair['drop'] as $d) {
                    $affectedIds[] = $d->id;
                }
            }
            $affectedIds = array_values(array_unique($affectedIds));

            $this->newLine();
            $this->info("[{$tag}] Creando snapshot {$snapshotTable}...");
            DB::statement("CREATE TABLE {$snapshotTable} AS SELECT * FROM storage_providers WHERE id IN (" . implode(',', $affectedIds) . ')');
            DB::statement("CREATE INDEX ON {$snapshotTable} (id)");

            $this->line("[{$tag}] Aplicando merges (conflict-aware delete + reassign)...");
            foreach ($mergePairs as $pair) {
                foreach ($pair['drop'] as $drop) {
                    $keep = $pair['keep'];
                    $this->line("  Merge #{$drop->id} '{$drop->name}' -> KEEP #{$keep->id} '{$keep->name}'");

                    $dropFileCount = DB::table('files')->where('storage_provider_id', $drop->id)->count();
                    $conflicts = DB::table('files as fd')
                        ->join('files as fk', function ($j) use ($keep) {
                            $j->on('fd.path', '=', 'fk.path')->where('fk.storage_provider_id', '=', $keep->id);
                        })
                        ->where('fd.storage_provider_id', $drop->id)
                        ->count();
                    $deletedConflicts = DB::table('files')
                        ->where('storage_provider_id', $drop->id)
                        ->whereIn('path', function ($q) use ($keep) {
                            $q->select('path')->from('files')->where('storage_provider_id', $keep->id);
                        })
                        ->delete();
                    $this->line("    files: {$dropFileCount} total, {$conflicts} en conflicto, {$deletedConflicts} borradas (redundantes)");

                    $filesReassigned = DB::table('files')
                        ->where('storage_provider_id', $drop->id)
                        ->update(['storage_provider_id' => $keep->id]);
                    if ($filesReassigned > 0) {
                        $this->line("    files reassign: {$drop->id} -> {$keep->id} ({$filesReassigned} paths únicos)");
                    }

                    $dropUsCount = DB::table('user_storages')->where('storage_provider_id', $drop->id)->count();
                    $deletedUs = DB::table('user_storages')
                        ->where('storage_provider_id', $drop->id)
                        ->whereIn('user_id', function ($q) use ($keep) {
                            $q->select('user_id')->from('user_storages')->where('storage_provider_id', $keep->id);
                        })
                        ->delete();
                    $usReassigned = DB::table('user_storages')
                        ->where('storage_provider_id', $drop->id)
                        ->update(['storage_provider_id' => $keep->id]);
                    if ($dropUsCount > 0) {
                        $this->line("    user_storages: {$dropUsCount} total, {$deletedUs} borradas (conflicto), {$usReassigned} reassign");
                    }

                    $deleted = DB::table('storage_providers')->where('id', $drop->id)->delete();
                    $this->line("    DELETE storage_providers #{$drop->id} ({$deleted} row)");
                }
            }

            $this->line("[{$tag}] UPDATE storage_providers.base_path ({$fromToken} -> {$toToken})...");
            $updateCount = DB::table('storage_providers')
                ->where('base_path', 'LIKE', "%{$fromToken}%")
                ->update(['base_path' => DB::raw("REPLACE(base_path, '{$fromToken}', '{$toToken}')")]);
            $this->line("  {$updateCount} base_paths actualizadas.");

            $this->line("[{$tag}] UPDATE transcriptions.source_absolute_path ({$fromToken} -> {$toToken})...");
            $txUpdate = DB::table('transcriptions')
                ->where('source_absolute_path', 'LIKE', "%{$fromToken}%")
                ->update(['source_absolute_path' => DB::raw("REPLACE(source_absolute_path, '{$fromToken}', '{$toToken}')")]);
            $this->line("  {$txUpdate} transcripciones actualizadas.");

            $this->line("[{$tag}] Invalidate caches...");
            foreach ($cacheToInvalidate as $id) {
                Cache::forget("transcriptor.scope.inherited.{$id}");
                Cache::forget("transcriptor.scope.{$id}");
                Cache::forget("folder_gen:{$id}:null");
            }
            Cache::forget('admin:storages:index');

            try {
                if (class_exists(\App\Services\Ia\CacheEpoch::class)) {
                    \App\Services\Ia\CacheEpoch::bump();
                }
            } catch (\Throwable $e) {
                $this->warn('  CacheEpoch::bump() no disponible: ' . $e->getMessage());
            }

            try {
                if (app()->bound(\App\Services\Ia\StorageHierarchyService::class)) {
                    $hierarchy = app(\App\Services\Ia\StorageHierarchyService::class);
                    if (method_exists($hierarchy, 'forgetAll')) {
                        $hierarchy->forgetAll();
                    }
                    foreach ($cacheToInvalidate as $id) {
                        if (method_exists($hierarchy, 'forget')) {
                            $hierarchy->forget((int) $id);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('  StorageHierarchyService::forget* no disponible: ' . $e->getMessage());
            }

            Log::info('storage_remap_disk.applied', [
                'tag' => $tag,
                'from' => $fromToken,
                'to' => $toToken,
                'snapshot_table' => $snapshotTable,
                'storages_count' => $updateCount,
                'transcriptions_count' => $txUpdate,
                'merge_pairs' => count($mergePairs),
                'affected_storage_ids' => $cacheToInvalidate,
            ]);
        });

        $this->newLine();
        $this->info('APPLY OK. Snapshot en: ' . $snapshotTable);
        $this->line('Verificacion post-apply:');
        $after = StorageProvider::query()
            ->whereIn('id', $cacheToInvalidate)
            ->orderBy('id')
            ->get(['id', 'name', 'base_path', 'parent_storage_id']);
        $this->table(['ID', 'Nombre', 'parent', 'base_path'], $after->map(fn($s) => [(int) $s->id, $s->name, (int) ($s->parent_storage_id ?? 0), $s->base_path])->toArray());

        $remainingG = StorageProvider::query()->where('base_path', 'LIKE', "%{$fromToken}%")->count();
        $remainingTx = DB::table('transcriptions')->where('source_absolute_path', 'LIKE', "%{$fromToken}%")->count();
        $this->line("Storages aun con '{$fromToken}': {$remainingG} (esperado 0)");
        $this->line("Transcripciones aun con '{$fromToken}': {$remainingTx} (esperado 0)");

        return Command::SUCCESS;
    }
}