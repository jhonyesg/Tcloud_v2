<?php

namespace App\Console\Commands;

use App\Services\StorageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Elimina filas duplicadas de `files`, conservando una por
 * `(storage_provider_id, path)`.
 *
 * Contexto: un montaje NFS caido provoco el borrado del arbol en BD; al quedar
 * todas las carpetas vacias, cada carga de pagina disparo un re-escaneo
 * concurrente y se insertaron 70.762 filas duplicadas (hasta 36 copias del mismo
 * archivo). El indice unico que lo habria impedido se elimino en la migracion
 * 2026_05_21_000002.
 *
 * ORDEN CRITICO: `files_parent_id_fkey` es ON DELETE CASCADE, asi que borrar una
 * carpeta duplicada arrastraria su subarbol. Por eso se re-parenta ANTES de
 * borrar, y se verifica en SQL que ningun superviviente cuelga de un padre
 * condenado.
 */
class DedupeFiles extends Command
{
    protected $signature = 'files:dedupe
                            {--dry-run : Analiza y reporta sin modificar nada}
                            {--storage= : Limitar a un storage_provider_id}
                            {--chunk=500 : Filas por transaccion}
                            {--keep-map : No borrar las tablas auxiliares al terminar}
                            {--yes : No pedir confirmacion}';

    protected $description = 'Elimina filas duplicadas de files conservando una por (storage_provider_id, path).';

    private bool $dryRun = false;

    public function handle(StorageSyncService $syncService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : null;
        $chunk = max(50, (int) $this->option('chunk'));

        // El contenedor de Postgres tiene /dev/shm de 64MB: sin esto, las
        // consultas de agrupacion sobre 1M de filas mueren con
        // "could not resize shared memory segment".
        DB::statement('SET max_parallel_workers_per_gather = 0');
        DB::statement("SET work_mem = '128MB'");

        if (!$this->assertInvariants()) {
            return Command::FAILURE;
        }

        $this->warnIfSyncEnabled();

        $this->line('Construyendo mapa de duplicados...');
        $this->buildMap($storageId);

        $stats = $this->mapStats();

        if ($stats['doomed'] === 0) {
            $this->info('No hay duplicados. Nada que hacer.');
            $this->dropMap();

            return Command::SUCCESS;
        }

        $this->report($stats);

        $droppedTx = $this->transcriptionsToDrop();
        if ($droppedTx) {
            $this->reportDroppedTranscriptions($droppedTx);
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: no se modifico nada. Las tablas auxiliares quedan para inspeccion.');
            $this->line('  select * from files_dedupe_map limit 20;');

            return Command::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Se borraran {$stats['doomed']} filas. ¿Continuar?", false)) {
            $this->warn('Cancelado.');
            $this->dropMap();

            return Command::SUCCESS;
        }

        // Recolectar las carpetas afectadas ANTES de borrar: despues, las filas
        // condenadas ya no existen para consultarlas.
        $affected = $this->affectedFolders();

        $this->repointReferences();
        $this->reparent($chunk);

        if (!$this->assertNoSurvivorUnderDoomedParent()) {
            $this->error('ABORTADO: hay supervivientes colgando de padres condenados. Borrar ahora los eliminaria por cascada.');

            return Command::FAILURE;
        }

        $deleted = $this->deleteDoomed($chunk);

        $this->invalidateCaches($syncService, $affected);

        if (!$this->option('keep-map')) {
            $this->dropMap();
        }

        $this->newLine();
        $this->info("Listo. Filas borradas: {$deleted}.");
        Log::info('files_dedupe.completed', ['deleted' => $deleted, 'storage_id' => $storageId]);

        $this->verify();

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------ invariantes

    /**
     * El algoritmo de una sola pasada depende de que `path` sea la ruta completa
     * y coherente con el padre. Si eso no se cumple, re-parentar podria crear
     * ciclos o dejar rutas incorrectas.
     */
    private function assertInvariants(): bool
    {
        $r = DB::selectOne("
            select
             (select count(*) from files c join files p on p.id = c.parent_id
               where c.path <> p.path || '/' || c.name)                                as bad_path,
             (select count(*) from files where parent_id is null and path like '%/%')   as bad_root,
             (select count(*) from files c where c.parent_id is not null
               and not exists (select 1 from files p where p.id = c.parent_id))         as orphan
        ");

        if ((int) $r->bad_path || (int) $r->bad_root || (int) $r->orphan) {
            $this->error('ABORTADO: el arbol no cumple el invariante path = parent.path || \'/\' || name.');
            $this->line("  rutas incoherentes: {$r->bad_path}");
            $this->line("  raices con '/':     {$r->bad_root}");
            $this->line("  huerfanos:          {$r->orphan}");
            $this->line('El de-duplicado de una sola pasada no es seguro en este estado.');

            return false;
        }

        $this->line('<fg=green>OK</> invariante del arbol verificado.');

        return true;
    }

    private function warnIfSyncEnabled(): void
    {
        if (config('storage_sync.enabled', true) && !$this->dryRun) {
            $this->warn('AVISO: storage_sync.enabled sigue en true. Se recomienda desactivarlo');
            $this->warn('       (STORAGE_SYNC_ENABLED=false + php artisan config:clear) durante la limpieza.');
        }
    }

    // -------------------------------------------------------------------- mapa

    private function buildMap(?int $storageId): void
    {
        $this->dropMap();

        $filter = $storageId !== null ? 'where storage_provider_id = ' . $storageId : '';

        DB::statement("
            create unlogged table files_dup_groups as
            select storage_provider_id, path
            from files {$filter}
            group by 1, 2 having count(*) > 1
        ");
        DB::statement('create index on files_dup_groups (storage_provider_id, path)');

        // El superviviente es el menor id, PREFIRIENDO el que ya tenga
        // transcripcion: asi no se descarta trabajo de GPU ya realizado.
        DB::statement("
            create unlogged table files_dedupe_map as
            with cand as (
                select f.id, f.storage_provider_id, f.path, f.parent_id,
                       (t.id is not null) as has_tx
                from files f
                join files_dup_groups g
                  on g.storage_provider_id = f.storage_provider_id and g.path = f.path
                left join transcriptions t on t.file_id = f.id
            ),
            keep as (
                select distinct on (storage_provider_id, path)
                       id as keep_id, storage_provider_id, path
                from cand
                order by storage_provider_id, path, has_tx desc, id asc
            )
            select c.id as dup_id, k.keep_id, c.storage_provider_id,
                   c.parent_id as parent_id_before, c.path, c.has_tx
            from cand c
            join keep k on k.storage_provider_id = c.storage_provider_id and k.path = c.path
            where c.id <> k.keep_id
        ");
        DB::statement('create unique index on files_dedupe_map (dup_id)');
        DB::statement('create index on files_dedupe_map (keep_id)');
    }

    private function dropMap(): void
    {
        DB::statement('drop table if exists files_dedupe_map');
        DB::statement('drop table if exists files_dup_groups');
    }

    private function mapStats(): array
    {
        $r = DB::selectOne('
            select
              (select count(*) from files_dup_groups)                              as groups,
              (select count(*) from files_dedupe_map)                              as doomed,
              (select count(*) from files f join files_dedupe_map m
                 on m.dup_id = f.parent_id)                                        as to_reparent,
              (select count(*) from files f join files_dedupe_map m
                 on m.dup_id = f.parent_id
                 where not exists (select 1 from files_dedupe_map m2 where m2.dup_id = f.id))
                                                                                   as survivors_to_reparent
        ');

        return [
            'groups' => (int) $r->groups,
            'doomed' => (int) $r->doomed,
            'to_reparent' => (int) $r->to_reparent,
            'survivors_to_reparent' => (int) $r->survivors_to_reparent,
        ];
    }

    private function report(array $stats): void
    {
        $this->newLine();
        $this->table(['Concepto', 'Filas'], [
            ['Grupos con duplicados', number_format($stats['groups'])],
            ['Filas a borrar', number_format($stats['doomed'])],
            ['Filas a re-parentar', number_format($stats['to_reparent'])],
            ['  de ellas, supervivientes', number_format($stats['survivors_to_reparent'])],
        ]);

        if ($stats['survivors_to_reparent'] > 0) {
            $this->warn("  {$stats['survivors_to_reparent']} filas legitimas cuelgan de un padre condenado:");
            $this->warn('  sin re-parentarlas primero, el CASCADE las borraria.');
        }

        $worst = DB::select('
            select path, storage_provider_id, count(*) + 1 as copias
            from files_dedupe_map group by 1, 2 order by copias desc limit 10
        ');

        if ($worst) {
            $this->newLine();
            $this->line('Peores casos:');
            $this->table(['Storage', 'Ruta', 'Copias'], array_map(
                fn ($r) => [$r->storage_provider_id, \Illuminate\Support\Str::limit($r->path, 70), $r->copias],
                $worst
            ));
        }
    }

    // -------------------------------------------------------------- referencias

    private function transcriptionsToDrop(): array
    {
        return DB::select('
            select t.id, t.state, m.path, m.keep_id,
                   (select count(*) from transcription_segments s where s.transcription_id = t.id) as segmentos
            from transcriptions t
            join files_dedupe_map m on m.dup_id = t.file_id
            where exists (select 1 from transcriptions t2 where t2.file_id = m.keep_id)
            order by t.id
        ');
    }

    private function reportDroppedTranscriptions(array $rows): void
    {
        $this->newLine();
        $this->warn('Transcripciones que se DESCARTAN (el superviviente ya tiene la suya):');
        $this->table(['tx_id', 'Estado', 'Segmentos', 'Ruta'], array_map(
            fn ($r) => [$r->id, $r->state, $r->segmentos, \Illuminate\Support\Str::limit($r->path, 60)],
            $rows
        ));
        $this->line('Son trabajo duplicado sobre el mismo audio: el resultado se conserva en la fila superviviente.');
    }

    /**
     * `transcriptions.file_id` es UNIQUE, asi que solo se puede repuntar cuando
     * el superviviente NO tiene ya su propia transcripcion. Las demas se
     * descartan con su fila.
     */
    private function repointReferences(): void
    {
        $tx = DB::update('
            update transcriptions t set file_id = m.keep_id
            from files_dedupe_map m
            where t.file_id = m.dup_id
              and not exists (select 1 from transcriptions t2 where t2.file_id = m.keep_id)
        ');

        $shares = DB::update('
            update shares s set file_id = m.keep_id
            from files_dedupe_map m where s.file_id = m.dup_id
        ');

        $jobs = 0;
        if ($this->tableHasColumn('media_edit_jobs', 'source_file_id')) {
            $jobs = DB::update('
                update media_edit_jobs j set source_file_id = m.keep_id
                from files_dedupe_map m where j.source_file_id = m.dup_id
            ');
        }

        $this->line("Referencias repuntadas — transcripciones: {$tx}, comparticiones: {$shares}, trabajos de edicion: {$jobs}");
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 as ok from information_schema.columns where table_name = ? and column_name = ?',
            [$table, $column]
        ) !== null;
    }

    // ------------------------------------------------------------- re-parentado

    private function reparent(int $chunk): void
    {
        $total = 0;

        do {
            $affected = DB::transaction(fn () => DB::update("
                with batch as (
                    select f.id, m.keep_id
                    from files f join files_dedupe_map m on m.dup_id = f.parent_id
                    limit {$chunk}
                )
                update files f set parent_id = b.keep_id from batch b where f.id = b.id
            "));

            $total += $affected;
            if ($affected > 0) {
                $this->output->write("\r  re-parentadas: {$total}   ");
            }
        } while ($affected > 0);

        $this->output->write("\r");
        $this->line("Filas re-parentadas: {$total}");
    }

    /**
     * La comprobacion mas importante del comando. Debe dar 0 antes del DELETE.
     */
    private function assertNoSurvivorUnderDoomedParent(): bool
    {
        $n = (int) DB::selectOne('
            select count(*) as n from files f
            join files_dedupe_map m on m.dup_id = f.parent_id
            where not exists (select 1 from files_dedupe_map m2 where m2.dup_id = f.id)
        ')->n;

        if ($n > 0) {
            Log::error('files_dedupe.unsafe_delete_aborted', ['survivors_under_doomed' => $n]);

            return false;
        }

        $this->line('<fg=green>OK</> ningun superviviente cuelga de un padre condenado.');

        return true;
    }

    private function deleteDoomed(int $chunk): int
    {
        $total = 0;

        do {
            $affected = DB::transaction(fn () => DB::delete("
                delete from files where id in (
                    select dup_id from files_dedupe_map order by dup_id limit {$chunk}
                )
            "));

            // Las filas ya borradas siguen en el mapa: se descuentan para no
            // repetirlas. El propio DELETE es idempotente (matchea 0 la segunda vez).
            if ($affected > 0) {
                DB::statement("
                    delete from files_dedupe_map where dup_id not in (select id from files)
                ");
                $total += $affected;
                $this->output->write("\r  borradas: {$total}   ");
            }
        } while ($affected > 0);

        $this->output->write("\r");

        return $total;
    }

    // ----------------------------------------------------------------- caches

    private function affectedFolders(): array
    {
        return DB::select('
            select distinct storage_provider_id, parent_id_before as parent_id from files_dedupe_map
            union
            select distinct f.storage_provider_id, f.parent_id
            from files f where f.id in (select keep_id from files_dedupe_map)
        ');
    }

    private function invalidateCaches(StorageSyncService $syncService, array $folders): void
    {
        foreach ($folders as $f) {
            $syncService->invalidateFolderCache((int) $f->storage_provider_id, $f->parent_id !== null ? (int) $f->parent_id : null);
        }

        $this->line('Caches de listado invalidadas: ' . count($folders) . ' carpetas.');
    }

    // ------------------------------------------------------------ verificacion

    private function verify(): void
    {
        $r = DB::selectOne("
            select
              (select coalesce(sum(c - 1), 0) from
                 (select count(*) c from files group by storage_provider_id, path) t) as dups,
              (select count(*) from files c join files p on p.id = c.parent_id
                 where c.path <> p.path || '/' || c.name)                             as bad_path,
              (select count(*) from files c where c.parent_id is not null
                 and not exists (select 1 from files p where p.id = c.parent_id))     as orphan,
              (select count(*) from transcriptions t
                 where not exists (select 1 from files f where f.id = t.file_id))     as tx_dangling,
              (select count(*) from shares s
                 where not exists (select 1 from files f where f.id = s.file_id))     as share_dangling
        ");

        $this->newLine();
        $this->table(['Verificacion', 'Valor', 'Esperado'], [
            ['Duplicados restantes', $r->dups, '0'],
            ['Rutas incoherentes', $r->bad_path, '0'],
            ['Huerfanos', $r->orphan, '0'],
            ['Transcripciones colgando', $r->tx_dangling, '0'],
            ['Comparticiones colgando', $r->share_dangling, '0'],
        ]);
    }
}
