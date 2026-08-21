<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAndTranscribeJob;
use App\Models\Transcription;
use App\Services\Ia\TranscriptorSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Re-transcribe las filas cuyo resultado se perdio en el transcriptor.
 *
 * Motivacion (2026-08-12): 33.571 transcripciones del 1 al 5 de agosto
 * quedaron en `queued` para siempre. El transcriptor respondia
 * GET /v1/jobs/{id} -> state=done, pero GET /v1/jobs/{id}/srt -> HTTP 500
 * porque el fichero habia sido purgado. El texto no existe en ninguna parte;
 * el audio original si sigue en disco, asi que la unica via de recuperacion es
 * volver a enviarlo.
 *
 * El poller cierra esas filas como `dead` con un prefijo de
 * Transcription::lossMarks(); este comando las selecciona por ese prefijo.
 *
 * Diseño: NO compite con las grabaciones del dia.
 *  - El tick solo despacha filas con created_at >= hoy, asi que dejar filas
 *    viejas en `pending` no las encolaria nunca: este comando despacha el
 *    mismo.
 *  - Usa TranscriptorSettings::computeDispatchBatch(), la misma aritmetica del
 *    tick, de modo que solo consume la capacidad que sobra por debajo de
 *    target_redis_queue.
 *  - Resetea a `pending` UNICAMENTE las filas que va a despachar en esta
 *    corrida. Si dejara un charco de miles de `pending` viejos, la fase 1 de
 *    PollResultsCommand los iria reenviando a 50/min con ffmpeg sincrono
 *    dentro del scheduler, saltandose este control de ritmo.
 */
class TranscriptionBackfillLostCommand extends Command
{
    protected $signature = 'transcription:backfill-lost
                            {--audit : Solo informe por dia y storage. No escribe nada}
                            {--dry-run : Muestra que haria sin tocar la BD ni la cola}
                            {--limit= : Tope de filas a reencolar en esta corrida}
                            {--day= : Solo filas creadas ese dia (YYYY-MM-DD)}
                            {--storage= : Solo filas de ese storage_provider_id}';

    protected $description = 'Reencola transcripciones cuyo resultado se perdio upstream (SRT purgado / job inexistente), respetando el regulador de cola.';

    public function handle(TranscriptorSettings $settings): int
    {
        if ($this->option('audit')) {
            return $this->audit();
        }

        $dryRun = (bool) $this->option('dry-run');

        $budget = $this->dispatchBudget($settings, $dryRun);
        if ($budget <= 0) {
            return Command::SUCCESS;
        }

        // Se piden mas filas de las que se van a despachar porque algunas se
        // descartaran por audio ausente, y esas no consumen presupuesto.
        $candidates = $this->candidates()
            ->with('file.storageProvider')
            ->orderBy('created_at')
            ->limit($budget * 3)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No hay transcripciones con resultado perdido pendientes de reencolar.');
            return Command::SUCCESS;
        }

        $minSize = $settings->int('min_file_size_bytes');
        $requeued = 0;
        $unrecoverable = 0;
        $errors = 0;

        foreach ($candidates as $tx) {
            if ($requeued >= $budget) {
                break;
            }

            $problem = $this->audioProblem($tx, $minSize);

            if ($problem !== null) {
                if ($dryRun) {
                    $this->line("  [dry-run] tx {$tx->id}: irrecuperable — {$problem}");
                } else {
                    $tx->update([
                        'state' => Transcription::STATE_DEAD,
                        'error_message' => $problem,
                        'finished_at' => now(),
                    ]);
                }
                $unrecoverable++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] tx {$tx->id} (file {$tx->file_id}, {$tx->created_at->toDateString()}): reencolaria");
                $requeued++;
                continue;
            }

            try {
                // Mismo reset que DiskScannerService::collectFailedCandidates(),
                // con retries a 0: el intento anterior no fallo por culpa de
                // este audio, se perdio el resultado despues de transcribirlo.
                $tx->update([
                    'state' => Transcription::STATE_PENDING,
                    'error_message' => null,
                    'job_id' => null,
                    'node_url' => null,
                    'node_id' => null,
                    'retries' => 0,
                    'requeue_after_at' => null,
                    'last_polled_at' => null,
                    'finished_at' => null,
                ]);

                ConvertAndTranscribeJob::dispatch($tx->file_id, (bool) $tx->generate_alerts);
                $requeued++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("tx {$tx->id}: {$e->getMessage()}");
                Log::error("backfill-lost: error reencolando tx {$tx->id}: {$e->getMessage()}");
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$prefix}Backfill: reencoladas={$requeued}, irrecuperables={$unrecoverable}, errores={$errors} (presupuesto={$budget}).");

        if (!$dryRun && ($requeued > 0 || $unrecoverable > 0)) {
            Log::info('backfill-lost: corrida completada', [
                'requeued' => $requeued,
                'unrecoverable' => $unrecoverable,
                'errors' => $errors,
                'budget' => $budget,
            ]);
        }

        $remaining = $this->candidates()->count();
        if ($remaining > 0) {
            $this->line("Quedan {$remaining} por reencolar. Repetir el comando cuando la cola baje del objetivo.");
        }

        return Command::SUCCESS;
    }

    /**
     * Filas cerradas sin resultado por perdida upstream, mas las que siguen
     * atascadas en queued/processing desde antes del corte por antiguedad (el
     * poller las ira cerrando, pero no hay que esperarle para rescatarlas).
     */
    private function candidates()
    {
        $maxAge = app(TranscriptorSettings::class)->int('poll_max_age_hours');

        $q = Transcription::query()->where(function ($outer) use ($maxAge) {
            $outer->where(function ($q) {
                $q->where('state', Transcription::STATE_DEAD)->upstreamLost();
            })->orWhere(function ($q) use ($maxAge) {
                $q->whereIn('state', [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING])
                  ->where('created_at', '<', now()->subHours($maxAge));
            });
        });

        if ($day = $this->option('day')) {
            $q->whereDate('created_at', $day);
        }

        if ($storageId = $this->option('storage')) {
            $q->whereHas('file', fn ($f) => $f->where('storage_provider_id', (int) $storageId));
        }

        return $q;
    }

    /**
     * @return string|null null si el audio sirve; si no, el motivo para dead.
     */
    private function audioProblem(Transcription $tx, int $minSize): ?string
    {
        $file = $tx->file;
        if (!$file || !$file->storageProvider) {
            return 'Backfill: archivo o storage asociado ya no existe en la BD.';
        }

        $path = rtrim((string) $file->storageProvider->base_path, '/')
              . '/' . ltrim((string) $file->path, '/');

        if (!is_file($path) || !is_readable($path)) {
            return "Backfill: audio original ya no esta en disco ({$path}). Transcripcion irrecuperable.";
        }

        $size = @filesize($path);
        if ($size !== false && $size < $minSize) {
            return "Backfill: audio de {$size} bytes, por debajo del minimo {$minSize}. Transcripcion irrecuperable.";
        }

        return null;
    }

    /**
     * Capacidad sobrante bajo target_redis_queue. Devuelve 0 (y explica por
     * que) si el pipeline del dia ya esta usando toda la cola.
     */
    private function dispatchBudget(TranscriptorSettings $settings, bool $dryRun): int
    {
        if ($settings->bool('dispatch_paused')) {
            $this->warn('dispatch_paused activo: no se reencola nada.');
            return 0;
        }

        try {
            $current = (int) Redis::llen('queues:transcription');
        } catch (\Throwable $e) {
            $this->error('No se pudo leer la cola Redis: ' . $e->getMessage());
            return 0;
        }

        $budget = $settings->computeDispatchBatch($current);

        if ($budget <= 0) {
            $this->info("Cola Redis en/sobre el objetivo (current={$current}). El backfill cede el paso al trabajo del dia.");
            return 0;
        }

        if ($limit = $this->option('limit')) {
            $budget = min($budget, max(0, (int) $limit));
        }

        $this->line(sprintf(
            '%sPresupuesto de esta corrida: %d (cola actual=%d, objetivo=%d).',
            $dryRun ? '[DRY-RUN] ' : '',
            $budget,
            $current,
            $settings->int('target_redis_queue'),
        ));

        return $budget;
    }

    /**
     * Informe sin escrituras: cuanto es recuperable, por dia y por storage.
     *
     * Usa files.size en vez de stat() para no lanzar decenas de miles de
     * llamadas contra NFS; la existencia real se comprueba en la corrida.
     */
    private function audit(): int
    {
        $rows = $this->candidates()->with('file.storageProvider')->get();

        if ($rows->isEmpty()) {
            $this->info('No hay transcripciones con resultado perdido.');
            return Command::SUCCESS;
        }

        $this->info("Transcripciones con resultado perdido: {$rows->count()}");
        $this->newLine();

        $byDay = $rows->groupBy(fn ($t) => $t->created_at?->toDateString() ?? 'sin fecha');
        $this->table(
            ['Dia', 'Filas', 'GB de audio'],
            $byDay->map(fn ($g, $day) => [
                $day,
                $g->count(),
                number_format($g->sum(fn ($t) => (int) ($t->file->size ?? 0)) / 1073741824, 2),
            ])->sortBy(0)->values()->all(),
        );

        $this->newLine();
        $byStorage = $rows->groupBy(fn ($t) => $t->file?->storageProvider?->name ?? 'sin storage');
        $this->table(
            ['Storage', 'Filas', 'GB de audio'],
            $byStorage->map(fn ($g, $name) => [
                $name,
                $g->count(),
                number_format($g->sum(fn ($t) => (int) ($t->file->size ?? 0)) / 1073741824, 2),
            ])->sortByDesc(1)->values()->all(),
        );

        $totalBytes = $rows->sum(fn ($t) => (int) ($t->file->size ?? 0));
        $sinArchivo = $rows->filter(fn ($t) => !$t->file || !$t->file->storageProvider)->count();

        $this->newLine();
        $this->line('Total audio a reprocesar: ' . number_format($totalBytes / 1073741824, 2) . ' GB');
        if ($sinArchivo > 0) {
            $this->warn("{$sinArchivo} filas ya no tienen archivo/storage en la BD: se cerraran como dead.");
        }
        $this->line('La existencia en disco se verifica fila a fila durante la corrida real.');

        return Command::SUCCESS;
    }
}
