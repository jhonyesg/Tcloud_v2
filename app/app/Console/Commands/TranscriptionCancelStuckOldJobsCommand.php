<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptorApiClient;
use App\Services\Ia\TranscriptorCancelAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Cancelacion controlada de jobs stuck en el transcriptor upstream.
 *
 * Caso de uso: limpiar filas locales con job_id valido que el upstream
 * lleva dias sin procesar (state IN queued/processing, started_at NULL,
 * created_at < cutoff). Sin esta limpieza ocupan slots en la cola upstream
 * indefinidamente y muestran al operador "jobs con fechas viejas" en la
 * validacion remota.
 *
 * Por diseno NO hace:
 *  - no toca archivos fisicos en disco (grabaciones quedan intactas).
 *  - no marca como dead las 16.582 filas pending sin job_id.
 *  - no se programa en routes/console.php: operacion manual del operador.
 *
 * Por diseno SI hace:
 *  - HTTP cancel contra /v1/jobs/{id}/cancel por cada candidato.
 *  - UPDATE local segun el codigo de respuesta (200 -> dead, 409 -> unchanged, 404 -> dead).
 *  - Audit log append-only en transcriptor_cancel_audit.
 *  - Stagger configurable entre cancelaciones (default 750 ms).
 *  - Guardarrail de ratio candidatos/total para abortar borrado masivo.
 *
 * Filtrado: state IN (queued, processing) AND job_id NOT NULL AND created_at < cutoff.
 * El flag --min-age-minutes protege contra cancelar jobs recien enviados (margen).
 */
class TranscriptionCancelStuckOldJobsCommand extends Command
{
    protected $signature = 'transcription:cancel-stuck-old-jobs
                            {--days=7 : Antiguedad minima en dias para considerar stuck}
                            {--min-age-minutes=60 : Margen minimo desde created_at antes de cancelar (protege jobs recien enviados)}
                            {--batch=100 : Tamano del chunk}
                            {--stagger-ms=750 : Pausa entre cada cancelacion (ms)}
                            {--max-ratio=0.5 : Ratio maxima candidatos/total antes de abortar}
                            {--dry-run : Contar candidatos sin mutar nada (default si no se pasa --apply)}
                            {--apply : Ejecutar la cancelacion}
                            {--actor-id= : user_id para auditoria (default null = CLI manual)}';

    protected $description = 'Cancela jobs viejos en el transcriptor upstream y marca las filas locales como dead. Operacion manual, no programada.';

    private const LOCK_KEY = 'transcription:cancel-stuck-old-jobs';
    private const LOCK_TTL = 600;

    public function handle(
        TranscriptorApiClient $client,
        TranscriptorCancelAudit $audit,
    ): int {
        $days = max(0, (int) $this->option('days'));
        $minAgeMinutes = max(0, (int) $this->option('min-age-minutes'));
        $batch = max(1, (int) $this->option('batch'));
        $staggerMs = max(0, (int) $this->option('stagger-ms'));
        $maxRatio = (float) $this->option('max-ratio');
        $dryRun = (bool) $this->option('dry-run') || !$this->option('apply');
        $actorIdRaw = $this->option('actor-id');
        $sessionUserId = (int) (Session::get('user_id') ?? 0);
        $actorId = $actorIdRaw !== null
            ? (int) $actorIdRaw
            : ($sessionUserId > 0 ? $sessionUserId : null);

        $this->info(sprintf(
            "transcription:cancel-stuck-old-jobs starting (days=%d, min_age_min=%d, batch=%d, stagger_ms=%d, max_ratio=%.2f%s)",
            $days,
            $minAgeMinutes,
            $batch,
            $staggerMs,
            $maxRatio,
            $dryRun ? ', DRY-RUN' : ', APPLY'
        ));

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (!$lock->get()) {
            $this->warn('Otra instancia en curso; abortando.');
            Log::info('transcriptor.cancel.skipped_locked');
            return self::SUCCESS;
        }

        try {
            $cutoff = CarbonImmutable::now()->subMinutes($minAgeMinutes)->subDays($days);

            $query = Transcription::query()
                ->whereIn('state', [Transcription::STATE_QUEUED, Transcription::STATE_PROCESSING])
                ->whereNotNull('job_id')
                ->where('created_at', '<', $cutoff);

            $candidates = (clone $query)->count();
            $total = Transcription::count();

            if ($total === 0) {
                $this->info('Sin transcripciones registradas.');
                return self::SUCCESS;
            }

            $ratio = $candidates / $total;
            $this->line(sprintf(
                'Candidatos (queued|processing con job_id y created_at<%s): %d de %d (ratio=%.4f, max=%.2f)',
                $cutoff->toIso8601String(),
                $candidates,
                $total,
                $ratio,
                $maxRatio,
            ));

            $byState = (clone $query)
                ->selectRaw('state, count(*) as c')
                ->groupBy('state')
                ->pluck('c', 'state')
                ->toArray();
            $this->line(sprintf(
                'Desglose por estado: %s',
                json_encode($byState, JSON_UNESCAPED_UNICODE)
            ));

            $oldest = (clone $query)->min('created_at');
            if ($oldest) {
                $this->line(sprintf('Mas viejo: %s', $oldest));
            }

            if ($ratio > $maxRatio) {
                Log::warning('transcriptor.cancel.aborted_mass_delete', [
                    'candidates' => $candidates,
                    'total' => $total,
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                    'days' => $days,
                    'min_age_minutes' => $minAgeMinutes,
                    'cutoff' => $cutoff->toIso8601String(),
                ]);
                $this->error(sprintf(
                    'ABORTADO: ratio %.4f excede max_ratio %.2f. Probablemente otro bug esta disparando la acumulacion; sube --max-ratio solo si estas seguro.',
                    $ratio,
                    $maxRatio
                ));
                return self::SUCCESS;
            }

            if ($dryRun) {
                Log::info('transcriptor.cancel.dry_run', [
                    'candidates' => $candidates,
                    'cutoff' => $cutoff->toIso8601String(),
                    'ratio' => round($ratio, 4),
                    'max_ratio' => $maxRatio,
                    'days' => $days,
                    'min_age_minutes' => $minAgeMinutes,
                ]);
                $this->info(sprintf('DRY-RUN: %d serian candidatos a cancelar.', $candidates));
                return self::SUCCESS;
            }

            // Modo --apply: iterar en chunks, cancelar uno por uno con stagger.
            $cancelled = 0;
            $skipped409 = 0;
            $errors = 0;
            $processed = 0;

            $query->orderBy('id')->chunkById($batch, function ($rows) use (
                &$cancelled,
                &$skipped409,
                &$errors,
                &$processed,
                $client,
                $audit,
                $actorId,
                $cutoff,
                $staggerMs,
            ) {
                foreach ($rows as $tx) {
                    if ($processed > 0 && $staggerMs > 0) {
                        usleep($staggerMs * 1000);
                    }
                    $processed++;

                    $result = $this->cancelOne($tx, $client, $audit, $actorId, $cutoff);
                    match ($result['outcome']) {
                        'cancelled' => $cancelled++,
                        'skipped_409' => $skipped409++,
                        'error' => $errors++,
                        default => null,
                    };
                }
            });

            Log::info('transcriptor.cancel.completed', [
                'cancelled' => $cancelled,
                'skipped_409' => $skipped409,
                'errors' => $errors,
                'processed' => $processed,
                'days' => $days,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            $this->info(sprintf(
                'Cancelados: %d, skipped (409 no-queued): %d, errores: %d, procesados: %d',
                $cancelled,
                $skipped409,
                $errors,
                $processed,
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('transcriptor.cancel.unhandled_exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Cancela un job individual en el upstream y actualiza el estado local.
     *
     * Outcomes:
     *  - cancelled: upstream devolvio 200; local pasa a dead.
     *  - skipped_409: upstream devolvio 409 (job no esta en queued);
     *    se asume que el upstream lo esta procesando o ya lo proceso.
     *  - error: cualquier otra respuesta (5xx, 4xx no 404/409, exception).
     *
     * @return array{outcome:string, code:int}
     */
    private function cancelOne(
        Transcription $tx,
        TranscriptorApiClient $client,
        TranscriptorCancelAudit $audit,
        ?int $actorId,
        CarbonImmutable $cutoff,
    ): array {
        $jobId = (string) $tx->job_id;
        $code = 0;
        $errorMsg = null;

        try {
            $response = $client->cancelUpstream($jobId);
            $code = 200;
            $this->markDead($tx, $cutoff);
            $audit->record($actorId, $jobId, $tx->id, $code, 'dead');
            $this->line(sprintf('[%d] cancel %s -> 200 cancelled local=dead', $tx->id, substr($jobId, 0, 12)));
            return ['outcome' => 'cancelled', 'code' => $code];
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, '409')) {
                $code = 409;
                $audit->record($actorId, $jobId, $tx->id, $code, 'unchanged', $msg);
                $this->line(sprintf('[%d] cancel %s -> 409 (no queued, skipping)', $tx->id, substr($jobId, 0, 12)));
                return ['outcome' => 'skipped_409', 'code' => $code];
            }

            if (str_contains($msg, '404')) {
                $code = 404;
                $this->markDead($tx, $cutoff, 'El job ya no existe en upstream (404); marcado dead localmente.');
                $audit->record($actorId, $jobId, $tx->id, $code, 'dead', $msg);
                $this->line(sprintf('[%d] cancel %s -> 404 local=dead', $tx->id, substr($jobId, 0, 12)));
                return ['outcome' => 'cancelled', 'code' => $code];
            }

            if (str_contains($msg, '503') || str_contains($msg, '504') || str_contains($msg, 'timeout') || str_contains($msg, 'Connection')) {
                $code = 503;
                $errorMsg = $msg;
                $audit->record($actorId, $jobId, $tx->id, $code, 'unchanged', $msg);
                Log::warning('transcriptor.cancel.upstream_unavailable', [
                    'tx_id' => $tx->id,
                    'job_id' => $jobId,
                    'error' => $msg,
                ]);
                $this->warn(sprintf('[%d] cancel %s -> 503 upstream unavailable; lote ABORTADO', $tx->id, substr($jobId, 0, 12)));
                throw $e;
            }

            $code = 500;
            $errorMsg = $msg;
            $audit->record($actorId, $jobId, $tx->id, $code, 'unchanged', $msg);
            Log::error('transcriptor.cancel.upstream_error', [
                'tx_id' => $tx->id,
                'job_id' => $jobId,
                'code' => $code,
                'error' => $msg,
            ]);
            $this->warn(sprintf('[%d] cancel %s -> %s (continuamos con el siguiente)', $tx->id, substr($jobId, 0, 12), $msg));
            return ['outcome' => 'error', 'code' => $code];
        }
    }

    /**
     * Marca una transcripcion como dead en BD con el mensaje estandar.
     */
    private function markDead(Transcription $tx, CarbonImmutable $cutoff, ?string $extraNote = null): void
    {
        $base = sprintf(
            'Cancelado en upstream por antiguedad: created_at<%s. transcription:cancel-stuck-old-jobs.',
            $cutoff->toDateString()
        );
        $message = $extraNote !== null ? "{$base} {$extraNote}" : $base;

        $tx->update([
            'state' => Transcription::STATE_DEAD,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
