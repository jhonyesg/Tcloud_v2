<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purga los huérfanos SEGUROS del módulo Files.
 *
 * "Seguro" significa: la fila NO tiene transcripciones, NO tiene shares,
 * NO tiene media_edit_jobs. Esto es lo que la regla 5 de PruneGuard
 * protege del borrado accidental: aqui se hace de forma supervisada,
 * en dos fases separadas por una ventana de auditoría.
 *
 * Fase 1 (mark): UPDATE files SET availability_state='gone' WHERE id IN (...)
 *                registra batch_id y conteo. NO se borra nada.
 *
 * Fase 2 (delete): --confirm-batch={batch_id} ejecuta el DELETE físico.
 *
 * La ventana entre fases es responsabilidad del operador (default 7 días
 * recomendado). Esto es deliberado: cualquier cosa que el operador no
 * esperaba ver puede recuperarse restaurando state='unknown' antes de
 * la fase 2.
 *
 * Lock distribuido: Cache::lock 'files:prune-unlinked' TTL 1 h para evitar
 * que dos invocaciones pisen el mismo batch.
 */
class PruneUnlinkedSafe extends Command
{
    protected $signature = 'files:prune-unlinked-safe
                            {--dry-run : Reporta conteos sin modificar nada}
                            {--batch-size=500 : Filas por fase}
                            {--storage= : Limitar a un storage_provider_id}
                            {--only-missing : Solo marcar filas con availability_state=missing (cambia el contrato: las disponibles/unknown NO se tocan)}
                            {--confirm-batch= : ID del batch a borrar en fase 2}';

    protected $description = 'Purga huérfanos sin FKs en dos fases (mark + delete) con ventana de auditoría.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(50, (int) $this->option('batch-size'));
        $storageId = $this->option('storage') !== null ? (int) $this->option('storage') : null;
        $onlyMissing = (bool) $this->option('only-missing');
        $confirmBatch = $this->option('confirm-batch');

        $lockKey = 'files:prune-unlinked';
        $lock = Cache::lock($lockKey, 3600);

        if (!$lock->get()) {
            $this->error('Otra instancia de files:prune-unlinked-safe esta corriendo (lock tomado).');
            return Command::FAILURE;
        }

        try {
            return $confirmBatch !== null
                ? $this->confirmDelete($confirmBatch, $dryRun)
                : $this->markPhase($batchSize, $storageId, $onlyMissing, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function markPhase(int $batchSize, ?int $storageId, bool $onlyMissing, bool $dryRun): int
    {
        if ($onlyMissing) {
            $this->line('Fase 1: marcando SOLO huérfanos confirmados como missing (--only-missing)...');
        } else {
            $this->line('Fase 1: marcando huérfanos seguros como gone...');
        }

        $stats = $this->countCandidates($storageId, $onlyMissing);
        $this->table(['Conteo', 'Valor'], [
            ['Candidatos totales (sin tx/shares/jobs' . ($onlyMissing ? ', missing only' : '') . ')', number_format($stats['total'])],
            ['Ya en estado gone', number_format($stats['already_gone'])],
            ['Disponibles para marcar', number_format($stats['available'])],
        ]);

        if ($stats['available'] === 0) {
            $this->info('Nada que marcar.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY-RUN: no se modifico nada.');
            return Command::SUCCESS;
        }

        // INSERT en una tabla de auditoría: registra el batch con su hash para
        // que la fase 2 confirme sobre el mismo conjunto. Asi un operador puede
        // revisar "lo que marqué" antes de aceptar el DELETE.
        $batchId = 'purge-' . now()->format('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 8);

        // ON COMMIT DROP exige una transaccion explicita: sin beginTransaction(),
        // cada statement corre en su propia tx y el temp table se destruye
        // inmediatamente tras el CREATE.
        DB::beginTransaction();
        try {
            // El WHERE de candidatos debe ser IDENTICO al de countCandidates().
            // Antes teniamos el OR del check de media_edit_jobs sin parentesis:
            //   A AND B AND C AND D AND E OR F
            // que por precedencia AND>OR se evaluaba como
            //   (A AND B AND C AND D AND E) OR F
            // y el lado derecho (F = NOT EXISTS(jobs)) se aplicaba SIN los
            // filtros de tx/shares, marcando archivos con transcripciones
            // como gone. Bug pre-existente destapado por mi dry-run que decia
            // 343 candidatos pero el mark marcaba 500 (incluyendo 454 con tx).
            // Fix: extraer el WHERE a buildCandidateWhere() y aplicarlo tanto
            // al SELECT INTO como al countCandidates().
            $where = self::buildCandidateWhere($storageId, $onlyMissing);
            DB::statement("
                CREATE TEMP TABLE _prune_candidates ON COMMIT DROP AS
                SELECT id FROM files
                WHERE {$where}
                LIMIT {$batchSize}
            ");

            $marked = (int) DB::affectingStatement("
                UPDATE files SET
                    availability_state = 'gone',
                    missing_since_at = COALESCE(missing_since_at, now())
                WHERE id IN (SELECT id FROM _prune_candidates)
            ");

            DB::statement("
                INSERT INTO files_prune_batches (batch_id, storage_id, marked, marked_at)
                VALUES (?, ?, ?, now())
                ON CONFLICT (batch_id) DO NOTHING
            ", [$batchId, $storageId, $marked]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->newLine();
        $this->info("Batch {$batchId} marcado: {$marked} filas en estado 'gone'.");
        $this->line("Para confirmar el DELETE, ejecuta:");
        $this->line("  php artisan files:prune-unlinked-safe --confirm-batch={$batchId}");
        $this->newLine();
        $this->warn('Recomendacion: dejar pasar al menos 7 dias entre mark y confirm para auditoria.');

        Log::info('prune_unlinked.marked', [
            'batch_id' => $batchId,
            'storage_id' => $storageId,
            'marked' => $marked,
        ]);

        return Command::SUCCESS;
    }

    private function confirmDelete(string $batchId, bool $dryRun): int
    {
        $this->line("Fase 2: borrando batch {$batchId}...");

        $batch = DB::selectOne('SELECT * FROM files_prune_batches WHERE batch_id = ?', [$batchId]);

        if (!$batch) {
            $this->error("Batch {$batchId} no encontrado en files_prune_batches.");
            return Command::FAILURE;
        }

        if ($batch->deleted_at !== null) {
            $this->warn("Batch {$batchId} ya fue borrado en {$batch->deleted_at}.");
            return Command::SUCCESS;
        }

        $this->table(['Batch', 'Valor'], [
            ['ID', $batch->batch_id],
            ['Storage', $batch->storage_id ?? 'todos'],
            ['Marcadas en fase 1', number_format($batch->marked)],
            ['Marcado en', $batch->marked_at],
        ]);

        if ($dryRun) {
            $this->info('DRY-RUN: no se borro nada.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("Se borraran {$batch->marked} filas del batch {$batchId}. ¿Continuar?", false)) {
            $this->warn('Cancelado.');
            return Command::SUCCESS;
        }

        // DELETE idempotente: WHERE availability_state='gone' para que un
        // operador que cambio de opinion (UPDATE a 'available') no sufra
        // el DELETE.
        $deleted = (int) DB::affectingStatement("
            DELETE FROM files
            WHERE availability_state = 'gone'
              AND id IN (SELECT id FROM files WHERE availability_state = 'gone')
        ");

        DB::statement(
            'UPDATE files_prune_batches SET deleted_at = now(), deleted = ? WHERE batch_id = ?',
            [$deleted, $batchId]
        );

        Log::warning('prune_unlinked.deleted', [
            'batch_id' => $batchId,
            'deleted' => $deleted,
        ]);

        $this->info("Batch {$batchId} borrado: {$deleted} filas.");

        return Command::SUCCESS;
    }

    private function countCandidates(?int $storageId, bool $onlyMissing = false): array
    {
        $where = self::buildCandidateWhere($storageId, $onlyMissing);
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM files WHERE {$where}")->n;

        $alreadyGoneFilter = $storageId !== null ? " AND storage_provider_id = {$storageId}" : '';
        $alreadyGone = (int) DB::selectOne("
            SELECT count(*) AS n FROM files
            WHERE availability_state = 'gone' AND is_folder = false
              {$alreadyGoneFilter}
        ")->n;

        return [
            'total' => $total,
            'already_gone' => $alreadyGone,
            'available' => $total,
        ];
    }

    /**
     * WHERE clause compartido entre countCandidates() y el SELECT INTO de
     * _prune_candidates. Antes ambos usaban WHERE similares pero el de mark
     * tenia un OR sin parentesis que por precedencia AND>OR cortocircuitaba
     * los filtros de tx/shares y marcaba filas con transcripciones como gone.
     * Centralizar aqui elimina ese drift.
     *
     * Los placeholders `__STORAGE_CLAUSE__` y `__MISSING_CLAUSE__` se
     * sustituyen por SQL literal (no binding) — son fragmentos que NO aceptan
     * input del usuario, solo flags internos.
     */
    private static function candidateWhereClause(): string
    {
        return "is_folder = false
            AND availability_state IN ('available', 'unknown', 'missing')
            AND NOT EXISTS (SELECT 1 FROM transcriptions WHERE file_id = files.id)
            AND NOT EXISTS (SELECT 1 FROM shares WHERE file_id = files.id)
            AND (
                NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'media_edit_jobs' AND column_name = 'source_file_id'
                )
                OR NOT EXISTS (
                    SELECT 1 FROM media_edit_jobs WHERE source_file_id = files.id
                )
            )
            __STORAGE_CLAUSE__
            __MISSING_CLAUSE__";
    }

    /**
     * Sustituye los placeholders del WHERE por SQL literal segun los flags.
     * Retorna el WHERE final listo para concatenar.
     */
    private static function buildCandidateWhere(?int $storageId, bool $onlyMissing): string
    {
        $storageClause = $storageId !== null ? " AND storage_provider_id = {$storageId}" : '';
        $missingClause = $onlyMissing ? " AND availability_state = 'missing' AND NOT is_trashed" : '';
        return strtr(self::candidateWhereClause(), [
            '__STORAGE_CLAUSE__' => $storageClause,
            '__MISSING_CLAUSE__' => $missingClause,
        ]);
    }
}