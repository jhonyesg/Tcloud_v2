<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procesa una Transcripción que pasó a done: descarga el SRT, lo parsea
 * en segmentos (aplicando correcciones), actualiza state y dispara el
 * matching de keywords. Usado tanto por el polling como por el runbook
 * de reintentos. La API v2 del transcriptor puede reescribir el SRT
 * después vía el corrector async (whisper-turbo); cuando eso ocurre,
 * `reprocessCorrected()` reemplaza los segmentos con la versión final.
 */
class TranscriptionProcessor
{
    public function __construct(
        private TranscriptorApiClient $client,
        private SrtParser $parser,
        private CorrectionService $corrections,
        private KeywordMatcher $matcher,
        private TranscriptionCoherencePass $coherence,
    ) {}

    /**
     * Procesa el SRT done de una transcripción.
     */
    public function processDone(Transcription $transcription): void
    {
        $this->processDoneWithSrt($transcription, null);
    }

    /**
     * Procesa el SRT done de una transcripción. Si se pasa $srt explícito
     * se usa directamente (evita un GET doble cuando el polling ya lo descargó).
     */
    public function processDoneWithSrt(Transcription $transcription, ?string $srt): void
    {
        if ($transcription->state === Transcription::STATE_DONE) {
            // Ya procesado; no duplicar. Para sobreescritura por corrector
            // async usar reprocessCorrected().
            return;
        }

        if (empty($transcription->job_id) || empty($transcription->node_url)) {
            throw new \RuntimeException('Transcription sin job_id/node_url para descargar SRT.');
        }

        if ($srt === null) {
            $srt = $this->client->getSrt($transcription->job_id, $transcription->node_url);
        }
        $segments = $this->parser->parse($srt);

        $this->persistSegmentsAndUpdate($transcription, $srt, $segments, triggerMatcher: true);
    }

    /**
     * Reemplaza los segmentos existentes con la versión corregida del SRT
     * (corrector async de la API). Pensado para llamarse desde el polling
     * cuando se detecta la transición `corrected: 0 → 1`.
     *
     * El matcher es idempotente (early return si ya hay matches), así que no
     * se duplican alertas; pero los segmentos pasan a reflejar el texto
     * corregido por la API, no por las correcciones locales del diccionario.
     */
    public function reprocessCorrected(Transcription $transcription, string $srt): void
    {
        if ($transcription->state !== Transcription::STATE_DONE) {
            // No estaba procesada: cae al flujo normal.
            $this->processDoneWithSrt($transcription, $srt);

            return;
        }

        $segments = $this->parser->parse($srt);

        // Borrar segmentos viejos antes de re-insertar. Matching previo se
        // mantiene: la transición 0→1 rara vez introduce keywords nuevos y
        // las alertas ya enviadas son idempotentes desde el lado del usuario.
        DB::transaction(function () use ($transcription, $segments) {
            $transcription->segments()->delete();
        });

        $this->persistSegmentsAndUpdate($transcription, $srt, $segments, triggerMatcher: false);
    }

    /**
     * Inserta segmentos + actualiza metadata. Centralizado para no duplicar
     * la logica entre processDoneWithSrt() y reprocessCorrected().
     */
    private function persistSegmentsAndUpdate(
        Transcription $transcription,
        string $srt,
        array $segments,
        bool $triggerMatcher
    ): void {
        // Aplicar correcciones approved: setea `text` desde `text_raw`.
        // Los segmentos vienen con clave `text`; mapear a `text_raw`.
        $segmentsForCorrections = array_map(fn ($s) => array_merge($s, ['text_raw' => $s['text']]), $segments);

        // Los canales que no emiten en español (Teleislas en criollo raizal,
        // emisoras con música en inglés) se guardan tal cual: su inglés es
        // correcto y el diccionario solo lo estropearía.
        $coherenceApplied = false;
        if ($this->corrections->appliesToTranscription($transcription)) {
            $segmentsForCorrections = $this->corrections->applyToSegments($segmentsForCorrections);

            // Pase de coherencia IA: corrige con LLM los segmentos con inglés
            // residual que el diccionario no cubrió (spanglish). Solo si el
            // canal emite en español. Fallback seguro: si el LLM falla, se
            // conserva el texto del diccionario.
            $segmentsForCorrections = $this->coherence->apply($segmentsForCorrections);
            $coherenceApplied = true;
        }

        DB::transaction(function () use ($transcription, $srt, $segmentsForCorrections, $coherenceApplied) {
            $rows = [];
            $now = now();
            foreach ($segmentsForCorrections as $seg) {
                $rows[] = [
                    'transcription_id' => $transcription->id,
                    'segment_index' => $seg['index'],
                    'start_seconds' => $seg['start_seconds'],
                    'end_seconds' => $seg['end_seconds'],
                    'text_raw' => $seg['text_raw'],
                    'text' => $seg['text'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($rows)) {
                // chunk insert para no exceder limits de params
                foreach (array_chunk($rows, 200) as $chunk) {
                    TranscriptionSegment::insert($chunk);
                }
            }

            // Hidratación post-INSERT de source_segment_id para las
            // correcciones emitidas por la coherencia IA. El extractor emite
            // las filas ANTES del INSERT, así que source_segment_id queda
            // null en ese momento; un UPDATE-JOIN (implementado en
            // TranscriptionCoherencePass::hydrateCoherenceLearnedSourceSegments)
            // las enlaza con el segmento origen usando position(wrong in text_raw).
            // Cambio 2026-08-18 (corrige bug de 6.035 pending sin source_segment_id).
            if ($coherenceApplied) {
                $this->coherence->hydrateCoherenceLearnedSourceSegments((int) $transcription->id);
            }

            $transcription->update([
                'state' => Transcription::STATE_DONE,
                'srt_content' => $srt,
                'duration_seconds' => $this->parser->calculateDuration($segmentsForCorrections),
                'word_count' => $this->parser->calculateWordCount($segmentsForCorrections),
                'finished_at' => $transcription->finished_at ?? $now,
                'error_message' => null,
            ]);
        });

        // Disparar matching contra text (corregido) solo si generate_alerts es true.
        // En reprocessCorrected() se omite: los matches ya existen y son idempotentes.
        if ($triggerMatcher && $transcription->generate_alerts) {
            try {
                $this->matcher->run($transcription);
            } catch (\Throwable $e) {
                Log::error("TranscriptionProcessor: matcher falló para transcripción {$transcription->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Marca la transcripción como error/dead con el mensaje dado.
     */
    public function markError(Transcription $transcription, string $state, string $message): void
    {
        $transcription->update([
            'state' => $state,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}