<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Procesa UN audio del transcriptor: ffmpeg local → POST WAV → update BD.
 *
 * Es el worker atomico que lanza TranscriptorBurstDispatchCommand en paralelo
 * (proc_open). Cada invocacion toma un tx_id y devuelve exit code:
 *   0 = ok (state=queued)
 *   1 = requeueable (reintento en siguiente rafaga)
 *   2 = error fatal (no reintentar)
 *
 * Se ejecuta con --no-interaction y captura su salida (stdout/stderr) en una
 * cache key efimera que el padre lee para reportar al operador.
 */
class TranscriptorDispatchOneCommand extends Command
{
    protected $signature = 'transcriptor:dispatch-one
                            {--tx= : ID de la fila Transcription a procesar}
                            {--parent-run-id= : runId del padre (para logs estructurados)}';

    protected $description = 'Procesa un audio (ffmpeg + POST upstream). Llamado por TranscriptorBurstDispatchCommand.';

    public function handle(TranscriptionSubmitService $submitter): int
    {
        $txId = (int) $this->option('tx');
        $parentRunId = (string) $this->option('parent-run-id');

        if ($txId <= 0) {
            $this->error('--tx requerido');
            return 2;
        }

        // Tomar la fila con lock para que otro worker no la agarre
        // concurrentemente (defensa en profundidad; el padre ya filtra
        // dispatched_at IS NULL pero un fork puede llegar tarde).
        $row = DB::transaction(function () use ($txId) {
            $r = Transcription::where('id', $txId)
                ->where('state', Transcription::STATE_PENDING)
                ->whereNull('dispatched_at')
                ->lockForUpdate()
                ->first();

            if (!$r) {
                return null;
            }

            // Marcar dispatched_at para sacarla del WHERE del worker PG (que
            // ya filtra dispatched_at IS NULL). Sin esto, otro worker PG
            // podria agarrarla en paralelo.
            DB::table('transcriptions')
                ->where('id', $r->id)
                ->update([
                    'dispatched_at' => now(),
                    'state' => Transcription::STATE_PROCESSING,
                ]);
            return $r->fresh();
        });

        if (!$row) {
            $this->warn("tx={$txId} no disponible (ya tomada o no pending)");
            return 1;
        }

        // Delegar a TranscriptionSubmitService (mismo path que el worker PG).
        // submit() hace ffmpeg local → POST → update state.
        $result = $submitter->submit($row);

        if ($result['ok'] ?? false) {
            $this->line(json_encode([
                'tx_id' => $row->id,
                'file_id' => $row->file_id,
                'job_id' => $result['job_id'] ?? null,
                'state' => 'queued',
                'run_id' => $parentRunId,
            ]));
            return 0;
        }

        if ($result['requeueable'] ?? false) {
            $this->line(json_encode([
                'tx_id' => $row->id,
                'requeueable' => true,
                'reason' => $result['error'] ?? 'unknown',
                'run_id' => $parentRunId,
            ]));
            return 1;
        }

        $this->line(json_encode([
            'tx_id' => $row->id,
            'error' => $result['error'] ?? 'unknown',
            'run_id' => $parentRunId,
        ]));
        return 2;
    }
}