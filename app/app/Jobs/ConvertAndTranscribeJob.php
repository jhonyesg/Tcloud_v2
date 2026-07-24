<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa un archivo individual del storage: convierte a opus y envía al
 * transcriptor externo. Procesado en paralelo por N workers supervisord
 * desde la cola Redis 'transcription'.
 *
 * Delega en TranscriptionSubmitService para evitar duplicar la lógica
 * ffmpeg+POST. Los endpoints síncronos de la UI (transcribeFile, dispatchNow)
 * usan el mismo servicio directamente.
 */
class ConvertAndTranscribeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnException = true;

    public function __construct(
        public int $fileId,
        public bool $generateAlerts = true
    ) {}

    /**
     * Dispatch: encola el job en la cola única 'transcription'.
     * Uso: ConvertAndTranscribeJob::dispatch($fileId, $alerts)
     */
    public static function dispatch(int $fileId, bool $generateAlerts = true)
    {
        $instance = new self($fileId, $generateAlerts);
        $instance->onQueue('transcription');
        return \dispatch($instance);
    }

    public function handle(TranscriptionSubmitService $submitter): ?array
    {
        $transcription = Transcription::where('file_id', $this->fileId)->first();
        if (!$transcription) {
            Log::warning("ConvertAndTranscribeJob: no existe Transcription para file_id {$this->fileId}.");
            return null;
        }

        // Idempotencia: si ya fue enviado a la API externa (tiene job_id),
        // no reenviar. Esto cubre el caso en que el schedule scan-and-submit
        // y un lote manual dispatchean el mismo archivo, o un worker muere
        // y el job se reencola después de que otro worker ya lo procesó.
        if (!empty($transcription->job_id)) {
            Log::info("ConvertAndTranscribeJob: file {$this->fileId} ya tiene job_id {$transcription->job_id}, se omite.");
            return null;
        }

        $result = $submitter->submit($transcription);

        return [
            'ok' => $result['ok'] ?? false,
            'job_id' => $result['job_id'] ?? null,
            'state' => $result['state'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }
}