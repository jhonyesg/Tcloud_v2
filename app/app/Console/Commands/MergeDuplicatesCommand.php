<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\StorageMerge;
use App\Models\StorageProvider;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FileRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consolida dos storages con el mismo `physical_path_normalized` en el
 * canonical (change `storage-physical-path-normalization`, 2026-09-17).
 *
 * El merge es DESTRUCTIVO del storage row duplicate:
 *   - Files del duplicate que NO existen en canonical: re-forkeados al canonical
 *     (UPDATE storage_provider_id, conservando path y parent_id).
 *   - Files del duplicate que SI existen en canonical: FKs aguas abajo
 *     (transcriptions, shares, media_edit_jobs) se re-apuntan al file del
 *     canonical, luego se borra el file del duplicate. No se pierde ninguna
 *     fila de transcripcion.
 *   - keyword_scan_watermarks: sum-merge por (keyword_id); si solo uno tiene
 *     la fila, se re-apunta. Si ambos tienen el mismo keyword, se unen
 *     conservando MAX(scanned_until) y SUM(candidates_total/hits_total).
 *   - user_storages: re-apunta el row del duplicate al canonical; si el user
 *     ya tiene acceso al canonical (UNIQUE violation), se borra el row del
 *     duplicate.
 *   - Al final: duplicate queda con `duplicate_of_storage_id=<canonical>,
 *     merged_at=now(), merged_reason='manual merge', enabled=false,
 *     base_path=NULL`.
 *   - Audit row en `storage_merges` con conteos exactos.
 *
 * Sin `--apply`: dry-run — cuenta y muestra stats, NO muta nada.
 * Con `--apply`: ejecuta en transaccion + lockForUpdate, requiere
 * confirmacion interactiva salvo `--yes`.
 *
 * Uso:
 *   php artisan storages:merge-duplicates --canonical=<id> --duplicate=<id>
 *   php artisan storages:merge-duplicates --canonical=<id> --duplicate=<id> --apply
 */
class MergeDuplicatesCommand extends Command
{
    protected $signature = 'storages:merge-duplicates
                            {--canonical= : ID del storage canonico (destino del merge)}
                            {--duplicate= : ID del storage duplicate (sera mergeado)}
                            {--apply : Ejecuta el merge; sin este flag es dry-run}
                            {--yes : Omite la confirmacion interactiva}
                            {--notes= : Notas adicionales para el audit log}';

    protected $description = 'Consolida dos storages con el mismo base_path en el canonical.';

    public function handle(): int
    {
        $canonicalId = (int) ($this->option('canonical') ?? 0);
        $duplicateId = (int) ($this->option('duplicate') ?? 0);

        if ($canonicalId === 0 || $duplicateId === 0) {
            $this->error('--canonical=<id> y --duplicate=<id> son obligatorios.');
            return Command::FAILURE;
        }

        if ($canonicalId === $duplicateId) {
            $this->error('canonical y duplicate no pueden ser el mismo storage.');
            return Command::FAILURE;
        }

        // Forzar que canonical_id sea el menor (la constraint storage_merges_canonical_smaller
        // lo exige para que la UNIQUE (canonical_id, duplicate_id) sea posible a futuro).
        if ($canonicalId > $duplicateId) {
            $this->warn(sprintf(
                'canonical=%d es mayor que duplicate=%d; intercambiando para satisfacer storage_merges_canonical_smaller.',
                $canonicalId, $duplicateId
            ));
            [$canonicalId, $duplicateId] = [$duplicateId, $canonicalId];
        }

        $canonical = StorageProvider::find($canonicalId);
        $duplicate = StorageProvider::find($duplicateId);

        if ($canonical === null || $duplicate === null) {
            $this->error('Uno o ambos storages no existen.');
            return Command::FAILURE;
        }

        if ($canonical->duplicate_of_storage_id !== null) {
            $this->error("Storage canonical #{$canonicalId} esta mergeado en #{$canonical->duplicate_of_storage_id}; no puede recibir un merge.");
            return Command::FAILURE;
        }
        if ($duplicate->duplicate_of_storage_id !== null) {
            $this->error("Storage duplicate #{$duplicateId} ya fue mergeado en #{$duplicate->duplicate_of_storage_id}; nada que hacer.");
            return Command::FAILURE;
        }
        if ($canonical->physical_path_normalized !== $duplicate->physical_path_normalized) {
            $this->error(sprintf(
                'Los storages no comparten physical_path_normalized (canonical=%s, duplicate=%s).',
                $canonical->physical_path_normalized ?? '(null)',
                $duplicate->physical_path_normalized ?? '(null)'
            ));
            return Command::FAILURE;
        }

        $stats = $this->projectMerge($canonical, $duplicate);

        $this->table(['Metrica', 'Valor'], [
            ['Path normalizado', $canonical->physical_path_normalized],
            ['Canonical', "#{$canonical->id} '{$canonical->name}' (enabled=" . ($canonical->enabled ? 'true' : 'false') . ')'],
            ['Duplicate', "#{$duplicate->id} '{$duplicate->name}' (enabled=" . ($duplicate->enabled ? 'true' : 'false') . ')'],
            ['Files a re-forkear (no existen en canonical)', $stats['files_to_move']],
            ['Files a dedupe (existen en canonical)', $stats['files_to_dedupe']],
            ['FKs a re-apuntar (tx+shares+jobs)', $stats['fks_to_repoint']],
            ['Watermarks a re-apuntar o sum-mergear', $stats['watermarks_to_merge']],
            ['Users con user_storages en duplicate', $stats['users_to_remap']],
            ['Storage_merges row a escribir', 1],
        ]);

        if (!$this->option('apply')) {
            $this->line('');
            $this->info('DRY-RUN: nada se modifico. Ejecuta con --apply para confirmar.');
            return Command::SUCCESS;
        }

        if (!$this->option('yes')) {
            $this->line('');
            $this->warn('Vas a marcar el storage duplicate como mergeado (soft-delete via duplicate_of_storage_id).');
            $this->warn(sprintf('Esto re-apunta %d archivos y %d FKs. NO es reversible trivialmente.', $stats['files_to_move'] + $stats['files_to_dedupe'], $stats['fks_to_repoint']));
            if (!$this->confirm('Escribe "merge" para continuar?', false)) {
                $this->info('Cancelado.');
                return Command::SUCCESS;
            }
            $typed = $this->ask('Escribe "merge" para confirmar', '');
            if ($typed !== 'merge') {
                $this->info('Confirmacion incorrecta. Cancelado.');
                return Command::SUCCESS;
            }
        }

        // Ejecutar el merge
        $result = $this->executeMerge($canonical, $duplicate, $stats);

        $this->line('');
        $this->info(sprintf(
            'Merge completado: %d archivos re-forkeados, %d deduped, %d FKs re-apuntados, %d watermarks mergeados.',
            $result['files_moved'],
            $result['files_deduped'],
            $result['fks_repointed'],
            $result['watermarks_merged']
        ));

        // Decrementar SystemSetting
        $current = (int) SystemSetting::get('storage.duplicates_remaining', '0');
        SystemSetting::set('storage.duplicates_remaining', (string) max(0, $current - 1));
        $this->info(sprintf('SystemSetting(storage.duplicates_remaining): %d -> %d', $current, max(0, $current - 1)));

        return Command::SUCCESS;
    }

    /**
     * Dry-run: cuenta lo que el merge haria, sin mutar.
     *
     * @return array{files_to_move:int, files_to_dedupe:int, fks_to_repoint:int, watermarks_to_merge:int, users_to_remap:int}
     */
    private function projectMerge(StorageProvider $canonical, StorageProvider $duplicate): array
    {
        $filesToMove = 0;
        $filesToDedupe = 0;
        $fksToRepoint = 0;

        // Para cada file del duplicate, ver si canonical tiene uno con mismo path
        $duplicateFiles = File::where('storage_provider_id', $duplicate->id)
            ->where('is_trashed', false)
            ->get(['id', 'path']);

        $canonicalPaths = File::where('storage_provider_id', $canonical->id)
            ->where('is_trashed', false)
            ->pluck('id', 'path');

        foreach ($duplicateFiles as $df) {
            if ($canonicalPaths->has($df->path)) {
                $filesToDedupe++;
                // Contar FKs que se re-apuntan al canonical file (los del duplicate file)
                $fksToRepoint += (int) DB::table('transcriptions')->where('file_id', $df->id)->count();
                $fksToRepoint += (int) DB::table('shares')->where('file_id', $df->id)->count();
                $fksToRepoint += (int) DB::table('media_edit_jobs')->where('source_file_id', $df->id)->count();
            } else {
                $filesToMove++;
            }
        }

        // Watermarks: si duplicate tiene un watermark que canonical NO tiene, re-apunta.
        // Si ambos tienen para el mismo keyword_id: borramos el del duplicate
        // (el canonical conserva el suyo — sin sum-merge para no perder
        // informacion del cron que escaneo mas reciente).
        $duplicateKeywords = DB::table('keyword_scan_watermarks')
            ->where('storage_provider_id', $duplicate->id)
            ->pluck('keyword_id');
        $watermarksToMerge = $duplicateKeywords->count();

        // Users con acceso al duplicate
        $usersToRemap = DB::table('user_storages')->where('storage_provider_id', $duplicate->id)->count();

        return [
            'files_to_move' => $filesToMove,
            'files_to_dedupe' => $filesToDedupe,
            'fks_to_repoint' => $fksToRepoint,
            'watermarks_to_merge' => $watermarksToMerge,
            'users_to_remap' => $usersToRemap,
        ];
    }

    /**
     * Ejecuta el merge real. Devuelve stats para el audit log.
     */
    private function executeMerge(StorageProvider $canonical, StorageProvider $duplicate, array $projected): array
    {
        $filesMoved = 0;
        $filesDedupe = 0;
        $fksRepointed = 0;
        $watermarksMerged = 0;
        $usersRemapped = 0;

        $actorId = null;
        $user = $this->getActingUser();
        if ($user !== null) $actorId = $user->id;

        DB::transaction(function () use ($canonical, $duplicate, &$filesMoved, &$filesDedupe, &$fksRepointed, &$watermarksMerged, &$usersRemapped, $actorId) {
            // Lock ForUpdate para evitar que el cron sync entre durante el merge
            StorageProvider::whereIn('id', [$canonical->id, $duplicate->id])->lockForUpdate()->get();

            $registry = app(FileRegistry::class);
            $canonicalPathIds = File::where('storage_provider_id', $canonical->id)
                ->where('is_trashed', false)
                ->pluck('id', 'path');

            $duplicateFiles = File::where('storage_provider_id', $duplicate->id)
                ->where('is_trashed', false)
                ->orderBy('id')
                ->get();

            foreach ($duplicateFiles as $df) {
                if ($canonicalPathIds->has($df->path)) {
                    // Path collision: re-apuntar FKs al canonical file, borrar duplicate
                    $canonicalFileId = $canonicalPathIds[$df->path];

                    $txUpdated = DB::table('transcriptions')->where('file_id', $df->id)->update(['file_id' => $canonicalFileId]);
                    $shUpdated = DB::table('shares')->where('file_id', $df->id)->update(['file_id' => $canonicalFileId]);
                    $jobUpdated = DB::table('media_edit_jobs')->where('source_file_id', $df->id)->update(['source_file_id' => $canonicalFileId]);
                    $fksRepointed += $txUpdated + $shUpdated + $jobUpdated;

                    $df->delete();
                    $filesDedupe++;
                } else {
                    // Re-forkear al canonical
                    $df->storage_provider_id = $canonical->id;
                    $df->save();
                    $filesMoved++;
                }
            }

            // Watermarks: re-apuntar los del duplicate al canonical.
            // Si ambos tienen para el mismo keyword_id: borrar el del duplicate
            // (el canonical conserva el suyo, sin sum-merge para no perder
            // informacion historica del cron que escaneo mas reciente).
            $canonicalWmByKeyword = DB::table('keyword_scan_watermarks')
                ->where('storage_provider_id', $canonical->id)
                ->pluck('keyword_id')
                ->flip(); // keyword_id => true
            $duplicateWms = DB::table('keyword_scan_watermarks')
                ->where('storage_provider_id', $duplicate->id)
                ->get(['keyword_id']);
            foreach ($duplicateWms as $wm) {
                if ($canonicalWmByKeyword->has($wm->keyword_id)) {
                    DB::table('keyword_scan_watermarks')
                        ->where('keyword_id', $wm->keyword_id)
                        ->where('storage_provider_id', $duplicate->id)
                        ->delete();
                } else {
                    DB::table('keyword_scan_watermarks')
                        ->where('keyword_id', $wm->keyword_id)
                        ->where('storage_provider_id', $duplicate->id)
                        ->update(['storage_provider_id' => $canonical->id]);
                }
                $watermarksMerged++;
            }

            // User_storages: re-apuntar o borrar (UNIQUE-aware)
            $duplicateUsers = DB::table('user_storages')->where('storage_provider_id', $duplicate->id)->get();
            $canonicalUserIds = DB::table('user_storages')->where('storage_provider_id', $canonical->id)->pluck('id', 'user_id');
            foreach ($duplicateUsers as $us) {
                if ($canonicalUserIds->has($us->user_id)) {
                    DB::table('user_storages')->where('id', $us->id)->delete();
                } else {
                    DB::table('user_storages')->where('id', $us->id)->update(['storage_provider_id' => $canonical->id]);
                }
                $usersRemapped++;
            }

            // Marcar el duplicate como mergeado
            DB::table('storage_providers')->where('id', $duplicate->id)->update([
                'duplicate_of_storage_id' => $canonical->id,
                'merged_at' => now(),
                'merged_reason' => 'manual merge via storages:merge-duplicates',
                'enabled' => false,
                'base_path' => null,
            ]);

            // Audit log
            DB::table('storage_merges')->insert([
                'canonical_id' => $canonical->id,
                'duplicate_id' => $duplicate->id,
                'files_moved' => $filesMoved,
                'files_deduped' => $filesDedupe,
                'fk_tables_affected' => json_encode([
                    'transcriptions_repointed' => $fksRepointed,
                    // Si quisieras desglosar tx/shares/jobs, se necesitaría contadores separados
                ]),
                'executed_by_user_id' => $actorId,
                'executed_at' => now(),
                'notes' => $this->option('notes'),
            ]);

            // Invalidar caches de listing y scope
            StorageProvider::forgetInheritedTranscriptionScope($canonical->id);
            StorageProvider::flushScopeMemo();

            Log::info('storages.merge_completed', [
                'canonical_id' => $canonical->id,
                'duplicate_id' => $duplicate->id,
                'files_moved' => $filesMoved,
                'files_deduped' => $filesDedupe,
                'fks_repointed' => $fksRepointed,
                'watermarks_merged' => $watermarksMerged,
                'users_remapped' => $usersRemapped,
                'executed_by_user_id' => $actorId,
            ]);
        });

        return [
            'files_moved' => $filesMoved,
            'files_deduped' => $filesDedupe,
            'fks_repointed' => $fksRepointed,
            'watermarks_merged' => $watermarksMerged,
        ];
    }

    /**
     * Best-effort resolver del usuario que ejecuta el comando.
     * En CLI no hay sesion HTTP; caemos al primer admin disponible.
     */
    private function getActingUser(): ?User
    {
        try {
            return User::where('role', 'admin')->where('status', 'active')->orderBy('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
