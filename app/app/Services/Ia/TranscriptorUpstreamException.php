<?php

namespace App\Services\Ia;

use App\Models\Transcription;

/**
 * Fallo de una llamada HTTP al transcriptor, con el status conservado.
 *
 * Existe para que el poller pueda distinguir dos cosas que antes llegaban
 * indistinguibles como RuntimeException con el status embebido en el texto:
 *
 *  - Transitorio: el nodo no responde, 502/503, timeout. Se reintenta el
 *    proximo ciclo; la fila sigue en queued legitimamente.
 *  - Perdida definitiva: el resultado ya no existe upstream y ninguna
 *    cantidad de reintentos lo va a traer. La fila debe cerrarse.
 *
 * El caso que motivo esta clase (2026-08-12): 33.571 filas del 1-5 de agosto
 * en las que GET /v1/jobs/{id} devuelve state=done pero GET .../srt devuelve
 * 500 porque el fichero fue purgado en el transcriptor. Se reintentaban cada
 * minuto de forma indefinida y el fallo era invisible.
 */
class TranscriptorUpstreamException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $status,
        private string $operation,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * 'job' (GET /v1/jobs/{id}) o 'srt' (GET /v1/jobs/{id}/srt).
     */
    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * ¿El resultado se perdio de forma irrecuperable upstream?
     *
     * - job 404: el registro del job ya no existe.
     * - srt 404: el job existe pero su SRT no.
     * - srt 5xx: el transcriptor tiene el job en su BD pero el fichero no esta
     *   en disco; responde 500 al intentar leerlo. Se trata como definitivo
     *   porque el caller solo pide el SRT tras ver state=done, y en ese punto
     *   un 5xx persistente significa fichero ausente, no indisponibilidad.
     *
     * Un 5xx en la consulta del job SI es transitorio: puede ser el nodo
     * reiniciando, y el job seguir intacto.
     */
    public function isDefinitiveLoss(): bool
    {
        if ($this->status === 404) {
            return true;
        }

        return $this->operation === 'srt' && $this->status >= 500;
    }

    /**
     * Mensaje que se persiste en `error_message`. Los prefijos vienen de
     * Transcription::lossMarks() porque transcription:backfill-lost selecciona
     * candidatas por ellos.
     */
    public function reason(): string
    {
        if ($this->operation === 'job' && $this->status === 404) {
            return Transcription::LOSS_MARK_JOB . ' (404): el transcriptor ya no conserva el registro del job.';
        }

        if ($this->operation === 'srt') {
            return Transcription::LOSS_MARK_SRT . " (HTTP {$this->status}): el job figura como done pero el fichero ya no esta disponible.";
        }

        return "Fallo upstream (HTTP {$this->status}) en {$this->operation}.";
    }
}
