<?php

namespace App\Services\Ia;

use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procesa una Transcripción que pasó a done: descarga el SRT, lo parsea
 * en segmentos (aplicando correcciones), actualiza state y dispara el
 * matching de keywords. Usado tanto por el webhook como por el polling
 * de respaldo (scan-stale).
 */
class TranscriptionProcessor
{
    public function __construct(
        private TranscriptorApiClient $client,
        private SrtParser $parser,
        private CorrectionService $corrections,
        private KeywordMatcher $matcher,
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
            // Ya procesado; no duplicar (idempotencia del matcher).
            return;
        }

        if (empty($transcription->job_id) || empty($transcription->node_url)) {
            throw new \RuntimeException('Transcription sin job_id/node_url para descargar SRT.');
        }

        if ($srt === null) {
            $srt = $this->client->getSrt($transcription->job_id, $transcription->node_url);
        }
        $segments = $this->parser->parse($srt);

        // Aplicar correcciones approved: setea `text` desde `text_raw`.
        // Los segmentos vienen con clave `text`; mapear a `text_raw`.
        $segmentsForCorrections = array_map(fn ($s) => array_merge($s, ['text_raw' => $s['text']]), $segments);
        $this->corrections->applyToSegments($segmentsForCorrections);

        DB::transaction(function () use ($transcription, $srt, $segmentsForCorrections) {
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

            $transcription->update([
                'state' => Transcription::STATE_DONE,
                'srt_content' => $srt,
                'duration_seconds' => $this->parser->calculateDuration($segmentsForCorrections),
                'word_count' => $this->parser->calculateWordCount($segmentsForCorrections),
                'finished_at' => $now,
                'error_message' => null,
            ]);
        });

        // Disparar matching contra text (corregido) solo si generate_alerts es true.
        if ($transcription->generate_alerts) {
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