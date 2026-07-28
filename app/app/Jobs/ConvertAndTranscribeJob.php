<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitTranscriptionConcurrency;
use App\Models\Transcription;
use App\Services\Ia\TranscriptionSubmitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class ConvertAndTranscribeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin limite por CONTEO, acotado por RELOJ.
     *
     * El semaforo de LimitTranscriptionConcurrency devuelve el job a la cola con
     * release() cuando no hay cupo, y release() incrementa attempts(). Con el
     * anterior $tries = 1, el primer job que el semaforo bloqueara fallaria de
     * forma permanente. Acotar por reloj es la semantica correcta para una
     * espera de semaforo. La propiedad de clase gana sobre el --tries=3 de las
     * units systemd.
     */
    public int $tries = 0;
    public int $timeout = 600;
    public bool $failOnException = true;

    /**
     * Un mismo file_id llega a la cola por cuatro caminos: el tick,
     * scan-and-submit, bulk-dispatch y el envio manual. uniqueFor va pareado con
     * queue.connections.redis.retry_after (900).
     */
    public int $uniqueFor = 900;

    public function __construct(
        public int $fileId,
        public bool $generateAlerts = true
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->fileId;
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new LimitTranscriptionConcurrency()];
    }

    /**
     * Dispatch: encola el job en la cola única 'transcription'.
     * Uso: ConvertAndTranscribeJob::dispatch($fileId, $alerts, $delayMs)
     *
     * $delayMs escalona la entrada a Redis (dispatch_stagger_ms). Sin él un lote
     * de 145 arranca 145 ffmpeg en el mismo instante; con 200ms se reparte.
     */
    public static function dispatch(int $fileId, bool $generateAlerts = true, int $delayMs = 0)
    {
        $instance = new self($fileId, $generateAlerts);
        $instance->onQueue('transcription');

        if ($delayMs > 0) {
            $instance->delay(now()->addMilliseconds($delayMs));
        }

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