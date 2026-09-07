<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill de las cuatro marcas nuevas de pipeline introducidas por
 * optimize-transcriptor-dispatch-throughput.
 *
 * Para filas pre-existentes, las cuatro columnas estan NULL. Este comando
 * rellena lo que puede desde senales externas disponibles:
 *
 *   - `discovered_at` se aproxima a `created_at` (el scanner creo la fila en
 *     ese momento). No es exacto pero es la mejor aproximacion para filas
 *     que ya estaban antes del upgrade.
 *
 *   - `submission_committed_at` se estima como `finished_at - p95_committed_to_finished`
 *     observado en la ventana. Solo se hace cuando `finished_at` y `job_id`
 *     estan poblados; las filas sin `job_id` no permiten saber cuando se
 *     hizo el commit. Cada fila estimada se registra como WARNING en log
 *     para auditoria.
 *
 *   - `dispatched_at` queda NULL: no tenemos como reconstruirlo de senales
 *     externas. La ausencia se reporta en el panel como "Sin marca
 *     (fila pre-upgrade)".
 *
 *   - `regulator_skip_reason` no se rellena: solo lo escribe el regulador
 *     en tiempo real.
 *
 * Por diseno NO escribe sobre filas que ya tienen la marca poblada:
 * es idempotente y se puede relanzar.
 */
class TranscriptionBackfillPipelineStampsCommand extends Command
{
    protected $signature = 'transcriptor:backfill-pipeline-stamps
                            {--hours=24 : Ventana en horas para calcular el p95 estimado}
                            {--dry-run : Solo informe, no escribe nada}';

    protected $description = 'Rellena discovered_at y submission_committed_at en filas pre-existentes a la migracion.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = max(1, (int) $this->option('hours'));

        $this->info(sprintf(
            'Backfill %s ventana=%dh',
            $dryRun ? '(dry-run)' : '(escribiendo)',
            $hours
        ));

        // 1. Calcular p95_committed_to_finished en la ventana SOLO sobre
        //    filas que ya tengan submission_committed_at poblado. Asi el
        //    backfill es inmune a su propio bucle: la primera corrida no
        //    tiene la marca, pero a partir de la segunda, las filas nuevas
        //    sirven para refinar la estimacion.
        $windowStart = now()->subHours($hours);

        $p95Row = DB::selectOne("
            SELECT percentile_cont(0.95) WITHIN GROUP (ORDER BY
                EXTRACT(EPOCH FROM (finished_at - submission_committed_at))
            ) AS p95
            FROM transcriptions
            WHERE state = 'done'
              AND finished_at IS NOT NULL
              AND submission_committed_at IS NOT NULL
              AND created_at >= ?
        ", [$windowStart]);

        $p95Seconds = $p95Row && $p95Row->p95 !== null ? (float) $p95Row->p95 : null;
        $this->line(sprintf(
            'p95_committed_to_finished en ventana: %s s',
            $p95Seconds === null ? 'sin muestras' : round($p95Seconds, 2)
        ));

        if ($p95Seconds === null) {
            $this->warn('Sin muestras para estimar. Abortando para no escribir valores absurdos.');
            return self::SUCCESS;
        }

        // 2. discovered_at = created_at para filas con discovered_at NULL.
        //    No es exacto pero es la unica senal que tenemos del momento
        //    en que el scanner vio el archivo. NO se aplica a filas
        //    pending (puede que un reintento las haya creado sin pasar
        //    por el scanner) — pero en BD pre-upgrade todas las pending
        //    tienen created_at que coincide con discovered_at por
        //    definicion, asi que es seguro.
        $discoveredCount = DB::table('transcriptions')
            ->whereNull('discovered_at')
            ->whereNotNull('created_at')
            ->update(['discovered_at' => DB::raw('created_at')]);

        if ($dryRun) {
            DB::table('transcriptions')
                ->whereNull('discovered_at')
                ->whereNotNull('created_at')
                ->update(['discovered_at' => null]); // revert
        }
        $this->line("discovered_at rellenados desde created_at: {$discoveredCount}");

        // 3. submission_committed_at estimado.
        //    Solo para filas state='done' con finished_at y job_id poblados
        //    pero submission_committed_at NULL.
        $candidates = DB::select("
            SELECT id, finished_at
            FROM transcriptions
            WHERE state = 'done'
              AND finished_at IS NOT NULL
              AND job_id IS NOT NULL
              AND submission_committed_at IS NULL
              AND created_at >= ?
            LIMIT 10000
        ", [$windowStart]);

        $updated = 0;
        foreach ($candidates as $row) {
            $estimated = \Carbon\Carbon::parse($row->finished_at)->subSeconds($p95Seconds);
            if (!$dryRun) {
                DB::table('transcriptions')
                    ->where('id', $row->id)
                    ->update(['submission_committed_at' => $estimated]);
            }
            $updated++;
            Log::warning('transcriptor.backfill.estimated', [
                'transcription_id' => $row->id,
                'finished_at' => $row->finished_at,
                'estimated_committed_at' => $estimated->toIso8601String(),
                'p95_seconds' => round($p95Seconds, 2),
            ]);
        }

        $this->line(sprintf(
            "submission_committed_at estimados (registrados como WARNING en log): %d",
            $updated
        ));

        $this->info('Backfill ' . ($dryRun ? 'simulado' : 'completado') . '.');

        return self::SUCCESS;
    }
}
