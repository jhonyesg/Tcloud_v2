<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Facades\Log;

/**
 * Recibe los resultados del transcriptor externo vía polling de
 * GET /v1/jobs/{job_id}, sin depender de webhook entrante.
 *
 * Cuando un job está done, descarga el SRT y lo persiste (segments,
 * correcciones, keywords) reutilizando TranscriptionProcessor.
 */
class TranscriptionPollingService
{
    public function __construct(
        private TranscriptorApiClient $client,
        private TranscriptionProcessor $processor,
        private TranscriptorSettings $settings,
    ) {}

    /**
     * Hace polling de todas las transcripciones en queued/processing con job_id.
     *
     * El limite estaba hardcodeado en 100, por debajo del objetivo de cola (140):
     * el poll no alcanzaba al dispatch y los jobs completados se acumulaban sin
     * recoger. Ahora es ajustable y su default va al nivel del objetivo.
     *
     * @return array{polled:int, done:int, errors:int, still_pending:int}
     */
    public function pollAll(): array
    {
        $jobs = Transcription::whereIn('state', [
            Transcription::STATE_QUEUED,
            Transcription::STATE_PROCESSING,
        ])
            ->whereNotNull('job_id')
            ->limit($this->settings->int('poll_limit'))
            ->get();

        $polled = 0;
        $done = 0;
        $errors = 0;
        $stillPending = 0;

        foreach ($jobs as $transcription) {
            $polled++;
            try {
                $remote = $this->client->getJob($transcription->job_id, $transcription->node_url ?? '');
                $state = $remote['state'] ?? $remote['status'] ?? null;

                if ($state === null) {
                    $stillPending++;
                    continue;
                }

                if ($state === Transcription::STATE_DONE) {
                    // Descargar el SRT (la srt_url puede venir absoluta o relativa).
                    $srtUrl = $remote['srt_url'] ?? null;
                    if ($srtUrl) {
                        $srt = $this->client->getSrtFromUrl($srtUrl, $transcription->node_url ?? '');
                        // processDone descarga el SRT por su cuenta; inyectamos el que ya tenemos
                        // para evitar un GET doble. Usamos processDoneWithSrt.
                        $this->processor->processDoneWithSrt($transcription->fresh(), $srt);
                    } else {
                        $this->processor->processDone($transcription->fresh());
                    }
                    $done++;
                } elseif (in_array($state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
                    $this->processor->markError(
                        $transcription,
                        $state,
                        $remote['error'] ?? ($remote['error_message'] ?? 'upstream error')
                    );
                    $errors++;
                } elseif ($state === 'cancelled') {
                    $this->processor->markError($transcription, Transcription::STATE_ERROR, 'Job cancelado en la API externa');
                    $errors++;
                } elseif ($state === Transcription::STATE_PROCESSING) {
                    if ($transcription->state !== Transcription::STATE_PROCESSING) {
                        $transcription->update(['state' => Transcription::STATE_PROCESSING]);
                    }
                    $stillPending++;
                } else {
                    // queued u otro: dejar como está.
                    $stillPending++;
                }
            } catch (\Throwable $e) {
                // Fallo de conectividad: no marcar como error, reintentar próximo ciclo.
                Log::debug("pollAll: fallo consultando job {$transcription->id} (job_id={$transcription->job_id}): {$e->getMessage()}");
                $stillPending++;
            }
        }

        return [
            'polled' => $polled,
            'done' => $done,
            'errors' => $errors,
            'still_pending' => $stillPending,
        ];
    }
}