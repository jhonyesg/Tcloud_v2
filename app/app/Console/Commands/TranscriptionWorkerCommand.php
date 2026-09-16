<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptionSubmitService;
use App\Services\Ia\TranscriptorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Worker PG que consume la tabla `transcriptions` directo con FOR UPDATE SKIP LOCKED.
 *
 * Reemplaza a las units supervisord `tcloud-transcription-batch-*`, eliminadas
 * por el cutover transcriptor-pg-native-queue (la cola ES la tabla
 * `transcriptions`, no una cola de jobs Bus).
 *
 * Por cada iteracion:
 *  1. BEGIN; SELECT ... FOR UPDATE SKIP LOCKED LIMIT 1.
 *  2. UPDATE state='processing', dispatched_at=NOW(); COMMIT (libera el lock).
 *  3. TranscriptionSubmitService::submit() (ffmpeg + POST al upstream).
 *  4. El submit ya muta state a queued/error/dead/requeue segun corresponda.
 *  5. Loguea 'transcriptor.worker.processed' con file_id + duration + result.
 *  6. Sleep idle_sleep si no habia candidatos.
 *
 * Signal handling: SIGTERM/SIGINT marcan $shouldStop=true y el loop termina
 * tras la iteracion en curso (stopwaitsecs=3600 en supervisord).
 */
class TranscriptionWorkerCommand extends Command
{
    protected $signature = 'transcription:worker
                            {--storage=ALL : storage_provider_id a procesar (ALL = todos)}
                            {--batch=1 : Filas por iteracion del loop}
                            {--idle-sleep=2 : Segundos de pausa cuando no hay candidatos}';

    protected $description = 'Worker PG: consume `transcriptions` con FOR UPDATE SKIP LOCKED y delega en TranscriptionSubmitService.';

    private bool $shouldStop = false;

    public function handle(TranscriptorSettings $settings): int
    {
        $this->installSignalHandlers();

        $batch = max(1, (int) $this->option('batch'));
        $idleSleep = max(1, (int) $this->option('idle-sleep'));
        $storageId = $this->option('storage');

        Log::info('transcriptor.worker.started', [
            'storage' => $storageId,
            'batch' => $batch,
            'idle_sleep' => $idleSleep,
            'pid' => getmypid(),
        ]);

        while (!$this->shouldStop) {
            $this->dispatchSignals();

            if ($settings->bool('dispatch_paused') || (string) env('TRANSCRIPTOR_DISPATCH_PAUSED') === 'true') {
                $this->dispatchSignals();
                sleep($idleSleep);
                continue;
            }

            try {
                $row = $this->claimRow($storageId, $batch);
            } catch (\Throwable $e) {
                Log::error('transcriptor.worker.claim_error: ' . $e->getMessage());
                $this->dispatchSignals();
                sleep($idleSleep);
                continue;
            }

            if ($row === null) {
                $this->dispatchSignals();
                sleep($idleSleep);
                continue;
            }

            foreach ($row as $claimed) {
                $this->processRow($claimed);
                $this->dispatchSignals();
                if ($this->shouldStop) {
                    break;
                }
            }
        }

        Log::info('transcriptor.worker.stopped', [
            'pid' => getmypid(),
        ]);

        return Command::SUCCESS;
    }

    /**
     * Toma hasta $batch filas `pending` con FOR UPDATE SKIP LOCKED y las muta
     * atomico a state='processing'. Devuelve los modelos refrescados.
     *
     * El filtro `requeue_after_at` es esencial: una fila rebotada por cola
     * remota llena o por /dev/shm bajo queda en pending con un aplazamiento.
     * Sin respetarlo, el worker la re-tomaria de inmediato en el siguiente
     * ciclo, gastaria ffmpeg y volveria a rebotarla: un busy-loop que quema
     * CPU sin avanzar. Con el filtro, la fila reaparece sola cuando vence.
     *
     * @return list<Transcription>|null  null si no hay candidatos
     */
    private function claimRow(string $storageFilter, int $batch): ?array
    {
        $todayBogota = CarbonImmutable::today();

        $claimedIds = DB::transaction(function () use ($storageFilter, $batch, $todayBogota) {
            $query = Transcription::query()
                ->where('state', Transcription::STATE_PENDING)
                ->whereNull('dispatched_at')
                ->where('recorded_at', '>=', $todayBogota)
                // Aplazamiento vigente: no tocar hasta que venza.
                ->where(function ($q) {
                    $q->whereNull('requeue_after_at')
                      ->orWhere('requeue_after_at', '<=', now());
                })
                // Prioridad a lo YA convertido por el stager: esas filas solo
                // necesitan el POST (barato, sin CPU local). Enviarlas primero
                // drena el inventario del RAM disk y hace que el goteo del
                // stager sea el ritmo real del pipeline, en vez de que cada
                // worker pague su propio ffmpeg en paralelo.
                ->orderByRaw('(staged_path IS NOT NULL) DESC')
                ->orderBy('recorded_at', 'desc')
                ->orderBy('discovered_at', 'desc')
                ->limit($batch)
                ->lockForUpdate();

            if ($storageFilter !== 'ALL' && ctype_digit((string) $storageFilter)) {
                $query->whereHas('file', function ($q) use ($storageFilter) {
                    $q->where('storage_provider_id', (int) $storageFilter);
                });
            }

            $pending = $query->get();

            if ($pending->isEmpty()) {
                return [];
            }

            $ids = $pending->pluck('id')->all();
            DB::table('transcriptions')
                ->whereIn('id', $ids)
                ->update([
                    'state' => Transcription::STATE_PROCESSING,
                    'dispatched_at' => now(),
                ]);

            return $ids;
        });

        if (empty($claimedIds)) {
            return null;
        }

        return Transcription::whereIn('id', $claimedIds)->get()->all();
    }

    private function processRow(Transcription $row): void
    {
        $start = microtime(true);
        $result = 'error';
        $errorMessage = null;
        $jobId = null;

        try {
            /** @var TranscriptionSubmitService $submitter */
            $submitter = app(TranscriptionSubmitService::class);

            // Camino preferido (fase 2): si la fila ya tiene el audio convertido
            // en tmpfs, solo se hace el POST — sin ffmpeg. Es lo que permite
            // drenar la cola remota sin picos de CPU en el host local.
            //
            // `submit()` (sincrono) queda como fallback para cuando el stager
            // aun no llego a esta fila o el staging esta deshabilitado: en ese
            // caso convierte y envia en un paso. Asi el pipeline nunca se
            // detiene por depender del cron del stager.
            $stagedPath = $row->staged_path;
            $hasStaged = is_string($stagedPath) && $stagedPath !== '' && is_file($stagedPath);

            $submit = $hasStaged
                ? $submitter->send($row, $stagedPath)
                : $submitter->submit($row);

            if ($submit['ok'] ?? false) {
                $result = $hasStaged ? 'ok_staged' : 'ok';
                $jobId = $submit['job_id'] ?? null;
            } elseif ($submit['requeueable'] ?? false) {
                $result = 'requeue';
            } else {
                $result = 'error';
                $errorMessage = $submit['error'] ?? null;
            }
        } catch (\Throwable $e) {
            $result = 'error';
            $errorMessage = $e->getMessage();
            Log::error('transcriptor.worker.unexpected: ' . $e->getMessage(), [
                'tx_id' => $row->id,
                'file_id' => $row->file_id,
            ]);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        Log::info('transcriptor.worker.processed', [
            'file_id' => $row->file_id,
            'storage_provider_id' => $row->file?->storage_provider_id,
            'duration_ms' => $durationMs,
            'result' => $result,
            'job_id' => $jobId,
            'error_message' => $errorMessage,
        ]);
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
        });
        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
        });
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}