<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use Illuminate\Support\Carbon;
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
     * @return array{polled:int, done:int, errors:int, still_pending:int, lost:int, aged_out:int}
     */
    public function pollAll(): array
    {
        $maxAgeHours = $this->settings->int('poll_max_age_hours');
        $ageCutoff = now()->subHours($maxAgeHours);

        // ORDER BY id DESC era una ventana FIJA sobre la cabeza de la tabla, no
        // un cursor: una fila que nunca resuelve se queda dentro de la ventana
        // para siempre y bloquea a las de detras. Con 33.571 filas asi (agosto
        // 1-5, SRT purgado upstream) ~139 de los 140 slots se gastaban cada
        // minuto en trabajo condenado.
        //
        // Ahora la ventana rota: primero lo nunca sondeado, luego lo que lleva
        // mas tiempo sin sondearse, asi que toda fila acaba consultandose.
        //
        // El desempate es started_at DESC (envio mas reciente primero), no
        // id DESC: el id refleja cuando se descubrio el archivo, no cuando se
        // mando a transcribir. Una fila de agosto reenviada por el backfill
        // tiene id bajo pero envio reciente, y su SRT recien generado es justo
        // el que conviene recoger antes de que el transcriptor lo purgue.
        $jobs = Transcription::whereIn('state', [
            Transcription::STATE_QUEUED,
            Transcription::STATE_PROCESSING,
        ])
            ->whereNotNull('job_id')
            ->orderByRaw('last_polled_at IS NOT NULL, last_polled_at ASC, started_at DESC NULLS LAST, id DESC')
            ->limit($this->settings->int('poll_limit'))
            ->get();

        $tally = self::emptyTally();

        foreach ($jobs as $transcription) {
            $this->pollOne($transcription, $ageCutoff, $maxAgeHours, $tally);
        }

        return $tally;
    }

    /** @return array{polled:int, done:int, errors:int, still_pending:int, lost:int, aged_out:int} */
    public static function emptyTally(): array
    {
        return ['polled' => 0, 'done' => 0, 'errors' => 0, 'still_pending' => 0, 'lost' => 0, 'aged_out' => 0];
    }

    /**
     * Sondea UNA transcripcion contra el transcriptor y sincroniza su estado.
     *
     * Vive aparte de pollAll() porque el boton "Refrescar estado" de la UI
     * tenia su propia copia de esta logica (ApiTranscriptorController::
     * syncFromUpstream) con criterios distintos: no clasificaba la perdida
     * upstream y se tragaba el fallo en un Log::debug. Sobre una fila con el
     * SRT purgado el boton no hacia nada en absoluto y no habia forma de
     * saberlo desde la pantalla. Un solo camino, un solo criterio.
     *
     * @param  array<string,int> $tally contadores acumulados, por referencia
     * @return string done|error|lost|aged_out|pending
     */
    public function pollOne(
        Transcription $transcription,
        ?Carbon $ageCutoff = null,
        ?int $maxAgeHours = null,
        array &$tally = [],
    ): string {
        $maxAgeHours ??= $this->settings->int('poll_max_age_hours');
        $ageCutoff ??= now()->subHours($maxAgeHours);
        $tally = array_merge(self::emptyTally(), $tally);

        $tally['polled']++;

        // Se marca ANTES de la llamada: si el proceso muere a mitad, la fila
        // pasa al final de la cola de sondeo en vez de reintentarse en bucle y
        // bloquear al resto.
        $transcription->forceFill(['last_polled_at' => now()])->saveQuietly();

        try {
            $remote = $this->client->getJob($transcription->job_id, $transcription->node_url ?? '');

            return $this->applyRemoteState($transcription, $remote, $ageCutoff, $maxAgeHours, $tally, 'poll');
        } catch (TranscriptorUpstreamException $e) {
            // Perdida definitiva: el resultado ya no existe upstream y
            // reintentar no lo va a traer. Cerrar la fila para que no siga
            // ocupando un slot del poll cada minuto.
            if ($e->isDefinitiveLoss()) {
                $this->processor->markError($transcription, Transcription::STATE_DEAD, $e->reason());
                $tally['lost']++;

                Log::warning('poll: resultado perdido upstream, transcripcion cerrada como dead', [
                    'tx_id' => $transcription->id,
                    'job_id' => $transcription->job_id,
                    'status' => $e->status(),
                    'operation' => $e->operation(),
                    'created_at' => $transcription->created_at?->toIso8601String(),
                ]);

                return 'lost';
            }

            return $this->transient($transcription, $e, $ageCutoff, $maxAgeHours, $tally);
        } catch (\Throwable $e) {
            // Timeout, fallo de conexion, error al persistir. Era Log::debug,
            // que LOG_LEVEL=warning descarta: ningun fallo del poller era
            // observable y por eso el backlog paso una semana inadvertido.
            return $this->transient($transcription, $e, $ageCutoff, $maxAgeHours, $tally);
        }
    }

    /**
     * Aplica una respuesta del upstream (obtenida via poll o via webhook) al
     * estado local de una Transcription. Idempotente: si llega dos veces
     * con el mismo body, no crea duplicados ni re-genera alertas.
     *
     * Refactor del 2026-09-14 (change `transcriptor-api-surface-completeness`):
     * la lógica antes vivía inline en pollOne(), ahora es reutilizable desde
     * el TranscriptionWebhookController.
     *
     * @param  string $source 'poll'|'webhook' — se loguea para diagnóstico
     */
    public function applyRemoteState(
        Transcription $transcription,
        array $remote,
        ?Carbon $ageCutoff,
        ?int $maxAgeHours,
        array &$tally,
        string $source = 'poll',
    ): string {
        $maxAgeHours ??= $this->settings->int('poll_max_age_hours');
        $ageCutoff ??= now()->subHours($maxAgeHours);
        $tally = array_merge(self::emptyTally(), $tally);

        $state = $remote['state'] ?? $remote['status'] ?? null;
        $remoteCorrected = array_key_exists('corrected', $remote) ? (int) $remote['corrected'] : null;

        if ($state === null) {
            return $this->settle($transcription, 'la API no devolvio estado', $ageCutoff, $maxAgeHours, $tally);
        }

        if ($state === Transcription::STATE_DONE) {
            $this->ingestDone($transcription, $remote, $remoteCorrected);
            $tally['done']++;

            return 'done';
        }

        if (in_array($state, [Transcription::STATE_ERROR, Transcription::STATE_DEAD], true)) {
            $this->processor->markError(
                $transcription,
                $state,
                $remote['error'] ?? ($remote['error_message'] ?? 'upstream error')
            );
            $tally['errors']++;

            return 'error';
        }

        if ($state === 'cancelled') {
            $this->processor->markError($transcription, Transcription::STATE_ERROR, 'Job cancelado en la API externa');
            $tally['errors']++;

            return 'error';
        }

        if ($state === Transcription::STATE_PROCESSING) {
            if ($transcription->state !== Transcription::STATE_PROCESSING) {
                $transcription->update(['state' => Transcription::STATE_PROCESSING]);
            }

            return $this->settle($transcription, 'sigue en processing upstream', $ageCutoff, $maxAgeHours, $tally);
        }

        // queued u otro: dejar como está.
        return $this->settle($transcription, "sigue en '{$state}' upstream", $ageCutoff, $maxAgeHours, $tally);
    }

    /**
     * Descarga el SRT de un job terminado y lo persiste.
     */
    private function ingestDone(Transcription $transcription, array $remote, ?int $remoteCorrected): void
    {
        $fresh = $transcription->fresh();
        $prevCorrected = $fresh->corrected;

        // Primer paso a done: descargar SRT y procesar (idempotente:
        // processDoneWithSrt aborta si ya está done).
        if ($prevCorrected === null || ($remoteCorrected === Transcription::CORRECTED_PENDING && $fresh->state !== Transcription::STATE_DONE)) {
            $srtUrl = $remote['srt_url'] ?? null;
            $srt = null;
            if ($srtUrl) {
                $srt = $this->client->getSrtFromUrl($srtUrl, $transcription->node_url ?? '');
            }

            // Deteccion de SRT truncado: la API upstream a veces marca como
            // done transcripciones que solo procesaron una fraccion del
            // audio (caso real: los40 streaming = 48s de 21 min). Validamos
            // chars SRT vs duracion_audio_reportada y reencolamos si el
            // resultado es demasiado corto.
            if ($srt !== null && $this->isSrtTruncated($fresh, $srt, $remote)) {
                $this->requeueTruncatedSrt($fresh, $srt, $remote);
                return;
            }

            if ($srt !== null) {
                $this->processor->processDoneWithSrt($fresh, $srt);
            } else {
                $this->processor->processDone($fresh);
            }
            $fresh->refresh();
        }

        // Tracking de `corrected`: persistir siempre que cambie, para
        // que la UI pueda mostrar el estado de la corrección async.
        if ($remoteCorrected !== null && $remoteCorrected !== $fresh->corrected) {
            $fresh->update(['corrected' => $remoteCorrected]);
        }

        // Transición 0→1: el corrector async reescribió el SRT en disco.
        // Re-descargar y re-procesar segmentos. El matcher es idempotente
        // (early return si ya hay matches), no se duplican alertas.
        if (
            $remoteCorrected === Transcription::CORRECTED_DONE
            && $prevCorrected === Transcription::CORRECTED_PENDING
        ) {
            try {
                $srt = $this->client->getSrt($fresh->job_id, $fresh->node_url ?? '');
                $this->processor->processDoneWithSrt($fresh, $srt);
            } catch (\Throwable $e) {
                Log::warning("poll: re-proceso corrected=1 falló para tx={$fresh->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Deteccion de SRT truncado: compara el ULTIMO timestamp del SRT contra
     * la duracion del audio reportada por la API. Caso real: la API upstream
     * marca como done transcripciones que solo procesaron una fraccion del
     * audio (los40 streaming = 48s de 21 min, el SRT termina con timestamps
     * alrededor del segundo 48).
     *
     * Settings:
     *   - min_srt_completion_pct (default 90): % minimo del audio que debe
     *     cubrir el SRT para considerarlo completo. Con 90%, un audio de 21 min
     *     cuyo SRT termina antes de 18.9 min se considera truncado.
     */
    private function isSrtTruncated(Transcription $transcription, string $srt, array $remote): bool
    {
        $audioSeconds = (float) ($remote['audio_seconds'] ?? 0);
        if ($audioSeconds <= 60) {
            return false;
        }
        $lastSrtSeconds = $this->extractLastSrtTimestamp($srt);
        if ($lastSrtSeconds === null) {
            return false;
        }
        $ratio = $lastSrtSeconds / $audioSeconds;
        $minRatio = $this->settings->int('min_srt_completion_pct') / 100.0;

        return $ratio < $minRatio;
    }

    /**
     * Extrae el ultimo timestamp (en segundos) del SRT parseando los headers
     * "--> HH:MM:SS,mmm" del ultimo cue. Devuelve null si el SRT no tiene
     * formato parseable.
     */
    private function extractLastSrtTimestamp(string $srt): ?float
    {
        if (!preg_match_all('/(\d{2}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})[,.](\d{3})/', $srt, $matches, PREG_SET_ORDER)) {
            return null;
        }
        if (empty($matches)) {
            return null;
        }
        $last = end($matches);
        $h = (int) $last[5];
        $m = (int) $last[6];
        $s = (int) $last[7];
        $ms = (int) $last[8];
        return $h * 3600 + $m * 60 + $s + $ms / 1000.0;
    }

    /**
     * En lugar de marcar como done, reencolar el job para retry. Si supera
     * el max de reintentos, se promueve a dead con detalle del problema.
     */
    private function requeueTruncatedSrt(Transcription $transcription, string $srt, array $remote): void
    {
        $audioSeconds = (float) ($remote['audio_seconds'] ?? 0);
        $lastSrtSeconds = $this->extractLastSrtTimestamp($srt) ?? 0;
        $ratio = $audioSeconds > 0 ? round(($lastSrtSeconds / $audioSeconds) * 100, 1) : 0;

        $retryCount = (int) ($transcription->srt_truncated_retries ?? 0) + 1;
        $maxRetries = $this->settings->int('srt_retry_max');

        if ($retryCount > $maxRetries) {
            $transcription->update([
                'state' => Transcription::STATE_DEAD,
                'error_message' => "SRT truncado agotado tras {$maxRetries} reintentos: ultimo timestamp a {$lastSrtSeconds}s de {$audioSeconds}s audio ({$ratio}% completado).",
                'finished_at' => now(),
            ]);
            Log::warning('TranscriptionPollingService: SRT truncado promueve a dead tras agotar reintentos', [
                'tx_id' => $transcription->id,
                'job_id' => $transcription->job_id,
                'last_srt_seconds' => $lastSrtSeconds,
                'audio_seconds' => $audioSeconds,
                'completion_pct' => $ratio,
                'retries' => $retryCount,
            ]);
            return;
        }

        $transcription->update([
            'state' => Transcription::STATE_PENDING,
            'job_id' => null,
            'error_message' => "SRT truncado: ultimo timestamp a {$lastSrtSeconds}s de {$audioSeconds}s audio ({$ratio}% completado). Reintento {$retryCount}/{$maxRetries}.",
            'srt_content' => null,
            'dispatched_at' => null,
            'started_at' => null,
            'submission_committed_at' => null,
            'last_polled_at' => null,
            'srt_truncated_retries' => $retryCount,
        ]);

        Log::warning('TranscriptionPollingService: SRT truncado reencolado para retry', [
            'tx_id' => $transcription->id,
            'job_id' => $transcription->job_id,
            'last_srt_seconds' => $lastSrtSeconds,
            'audio_seconds' => $audioSeconds,
            'completion_pct' => $ratio,
            'retries' => $retryCount,
        ]);
    }

    /**
     * Fallo no concluyente: se reintenta, salvo que la fila ya sea demasiado
     * vieja para que tenga sentido seguir sondeandola.
     *
     * @param array<string,int> $tally
     */
    private function transient(
        Transcription $transcription,
        \Throwable $e,
        Carbon $ageCutoff,
        int $maxAgeHours,
        array &$tally,
    ): string {
        $outcome = $this->settle($transcription, "ultimo fallo: {$e->getMessage()}", $ageCutoff, $maxAgeHours, $tally);

        if ($outcome === 'pending') {
            Log::warning('poll: fallo consultando job, se reintentara', [
                'tx_id' => $transcription->id,
                'job_id' => $transcription->job_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $outcome;
    }

    /**
     * Red de seguridad general: ninguna fila puede quedarse en un estado no
     * terminal indefinidamente, sea cual sea la causa. Cubre tanto los fallos
     * como el caso en que la API responde correctamente pero el job nunca
     * avanza de queued.
     *
     * @param array<string,int> $tally
     */
    private function settle(
        Transcription $transcription,
        string $detail,
        Carbon $ageCutoff,
        int $maxAgeHours,
        array &$tally,
    ): string {
        // started_at es la marca del envio efectivo, no la de creacion de la
        // fila: una transcripcion reenviada por el backfill conserva su
        // created_at original (agosto) y con el se cerraria como caducada nada
        // mas enviarla.
        $submittedAt = $transcription->started_at ?? $transcription->created_at;

        if ($submittedAt === null || $submittedAt->gte($ageCutoff)) {
            $tally['still_pending']++;

            return 'pending';
        }

        $this->processor->markError(
            $transcription,
            Transcription::STATE_DEAD,
            Transcription::LOSS_MARK_AGED . " {$maxAgeHours}h en queued/processing ({$detail}).",
        );
        $tally['aged_out']++;

        Log::warning('poll: transcripcion cerrada por antiguedad', [
            'tx_id' => $transcription->id,
            'job_id' => $transcription->job_id,
            'submitted_at' => $submittedAt->toIso8601String(),
            'max_age_hours' => $maxAgeHours,
            'detail' => $detail,
        ]);

        return 'aged_out';
    }
}
