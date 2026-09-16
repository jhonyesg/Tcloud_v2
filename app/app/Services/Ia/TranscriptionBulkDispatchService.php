<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio centralizado para marcar transcripciones como processing directo en
 * la tabla `transcriptions` (cola nativa PG).
 *
 * Reemplaza al dispatch directo eliminado por el cutover
 * transcriptor-pg-native-queue. Usado por:
 *  - POST /ia/api-transcriptor/jobs/bulk-dispatch (controller)
 *  - ScanAndSubmitCommand (fase 2, opcional)
 *  - TranscriptionBackfillLostCommand (cada fila reencolada)
 *  - scripts/inject_pending_with_metrics.php (modo debug)
 *
 * El bulk dispatch pone filas a state='processing', asi que el worker PG
 * (transcription:worker) NO las re-toma (filtra state='pending'). El submit
 * corre sincronicamente aqui mismo, reutilizando TranscriptionSubmitService.
 */
class TranscriptionBulkDispatchService
{
    public function __construct(private TranscriptionSubmitService $submitter) {}

    /**
     * Marca las filas indicadas como processing y lanza el submit sincronico.
     *
     * @param  list<int>  $ids  IDs de la tabla transcriptions; vacio = auto-select
     *                          hasta 2000 pendientes sin dispatched_at.
     * @return array{enqueued:int, skipped_queued:int, errors:int}
     */
    public function dispatch(array $ids): array
    {
        if (empty($ids)) {
            $ids = Transcription::where('state', Transcription::STATE_PENDING)
                ->whereNull('dispatched_at')
                ->where('recorded_at', '>=', CarbonImmutable::today())
                ->orderBy('recorded_at', 'desc')
                ->limit(2000)
                ->pluck('id')
                ->all();
        }

        $enqueued = 0;
        $skipped = 0;
        $errors = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Transcription::whereIn('id', $chunk)->get();
            foreach ($rows as $row) {
                if (in_array($row->state, [Transcription::STATE_DONE, Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
                    $skipped++;
                    continue;
                }
                if (!is_null($row->job_id)) {
                    $skipped++;
                    continue;
                }

                try {
                    // Mark-as-processing NO va dentro de una transaccion larga:
                    // lo hacemos en su propio UPDATE atomico y luego llamamos a
                    // submit() fuera de cualquier transaccion para que el
                    // ffmpeg+POST (potencialmente minutos) no retenga un lock
                    // de fila ni bloquee al watchdog.
                    $affected = DB::table('transcriptions')
                        ->where('id', $row->id)
                        ->where('state', Transcription::STATE_PENDING)
                        ->whereNull('dispatched_at')
                        ->update([
                            'dispatched_at' => now(),
                            'state' => Transcription::STATE_PROCESSING,
                        ]);

                    if ($affected === 0) {
                        $skipped++;
                        continue;
                    }

                    $row->refresh();
                    $result = $this->submitter->submit($row);

                    if (!($result['ok'] ?? false)) {
                        Log::warning('TranscriptionBulkDispatch: submit fallo', [
                            'tx_id' => $row->id,
                            'error' => $result['error'] ?? null,
                        ]);
                    }

                    $enqueued++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('TranscriptionBulkDispatch: error despachando tx ' . $row->id . ': ' . $e->getMessage());
                }
            }
        }

        return ['enqueued' => $enqueued, 'skipped_queued' => $skipped, 'errors' => $errors];
    }
}