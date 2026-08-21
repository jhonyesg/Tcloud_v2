<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Transcription;
use App\Models\TranscriptionReview;
use Illuminate\Support\Facades\DB;

/**
 * Detector de segmentos con inglés residual (changes/2026-08-11-english-residual-segment-detector).
 *
 * Tokeniza cada segmento, clasifica tokens en en/es/unknown usando listas
 * curadas (config/corrections.php:english_residual) y un fallback ortográfico
 * (presencia de tilde/ñ → es). Calcula un score = en / (en + es) para medir
 * la mezcla residual post-corrección.
 *
 * Uso típico:
 *   $detector = app(EnglishResidualSegmentDetector::class);
 *   $flagged = $detector->findFlaggedTranscriptions(0.4, 1); // threshold, days
 *   foreach ($flagged as $f) {
 *       $detector->flagForReview($f['transcription_id'], $reviewerId, $f);
 *   }
 */
class EnglishResidualSegmentDetector
{
    /** @var array<string, true> */
    private array $enFunctions;

    /** @var array<string, true> */
    private array $esStopwords;

    private float $defaultThreshold;

    public function __construct()
    {
        $cfg = (array) config('corrections.english_residual', []);
        $this->enFunctions = array_flip(array_map(
            fn($w) => mb_strtolower($w),
            (array) ($cfg['en_functions'] ?? [])
        ));
        $this->esStopwords = array_flip(array_map(
            fn($w) => mb_strtolower($w),
            (array) ($cfg['es_stopwords'] ?? [])
        ));
        $this->defaultThreshold = (float) ($cfg['threshold'] ?? 0.4);
    }

    /**
     * Tokeniza y clasifica un texto. Retorna score, conteos y detalle de hits.
     *
     * @return array{score: float, en: int, es: int, unknown: int, hits: array<int, array{token: string, lang: string}>}
     */
    public function scoreSegment(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['score' => 0.0, 'en' => 0, 'es' => 0, 'unknown' => 0, 'hits' => []];
        }

        $tokens = preg_split('/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || empty($tokens)) {
            return ['score' => 0.0, 'en' => 0, 'es' => 0, 'unknown' => 0, 'hits' => []];
        }

        $en = 0;
        $es = 0;
        $unknown = 0;
        $hits = [];

        foreach ($tokens as $tok) {
            $lower = mb_strtolower($tok);
            $lang = $this->classifyToken($lower, $tok);
            if ($lang === 'en') {
                $en++;
                $hits[] = ['token' => $tok, 'lang' => 'en'];
            } elseif ($lang === 'es') {
                $es++;
                $hits[] = ['token' => $tok, 'lang' => 'es'];
            } else {
                $unknown++;
            }
        }

        $denom = $en + $es;
        $score = $denom > 0 ? $en / $denom : 0.0;

        return [
            'score' => $score,
            'en' => $en,
            'es' => $es,
            'unknown' => $unknown,
            'hits' => $hits,
        ];
    }

    /**
     * Clasifica un token individual.
     */
    private function classifyToken(string $lower, string $original): string
    {
        if (isset($this->enFunctions[$lower])) {
            return 'en';
        }
        if (isset($this->esStopwords[$lower])) {
            return 'es';
        }
        // Tilde o ñ: inequívocamente español.
        if (preg_match('/[áéíóúñ]/u', $original)) {
            return 'es';
        }
        return 'unknown';
    }

    /**
     * Puntúa toda una transcripción. Retorna resumen agregado.
     *
     * @return array{transcription_id: int, total_segments: int, max_score: float, avg_score: float, flagged_segments: array<int, array{segment_index: int, score: float, text_preview: string}>}
     */
    public function scoreTranscription(int $transcriptionId, ?float $threshold = null): array
    {
        $threshold = $threshold ?? $this->defaultThreshold;
        $segments = DB::table('transcription_segments')
            ->where('transcription_id', $transcriptionId)
            ->orderBy('segment_index')
            ->get(['segment_index', 'text']);

        $total = 0;
        $flagged = [];
        $maxScore = 0.0;
        $scoreSum = 0.0;

        foreach ($segments as $seg) {
            $txt = (string) $seg->text;
            if ($txt === '') {
                continue;
            }
            $total++;
            $scored = $this->scoreSegment($txt);
            $scoreSum += $scored['score'];
            if ($scored['score'] > $maxScore) {
                $maxScore = $scored['score'];
            }
            if ($scored['score'] >= $threshold) {
                $flagged[] = [
                    'segment_index' => (int) $seg->segment_index,
                    'score' => $scored['score'],
                    'text_preview' => mb_substr($txt, 0, 200),
                    'en' => $scored['en'],
                    'es' => $scored['es'],
                ];
            }
        }

        return [
            'transcription_id' => $transcriptionId,
            'total_segments' => $total,
            'max_score' => $maxScore,
            'avg_score' => $total > 0 ? $scoreSum / $total : 0.0,
            'flagged_segments' => $flagged,
        ];
    }

    /**
     * Encuentra transcripciones con al menos un segmento que supere el threshold.
     *
     * OPTIMIZACIÓN: query única con regex pre-filter, scoring en PHP
     * sobre los segmentos filtrados, agrupación en memoria por transcription_id.
     *
     * @return array<int, array{transcription_id: int, finished_at: ?string, flagged_count: int, max_score: float, flagged_segment_indexes: array}>
     */
    public function findFlaggedTranscriptions(?float $threshold, int $daysBack): array
    {
        $threshold = $threshold ?? $this->defaultThreshold;
        $since = now()->subDays(max(1, $daysBack));
        $enRegex = $this->buildEnRegex();

        // 1) Query única: segmentos con EN regex en transcripciones done
        //    de la ventana. JOIN para traer finished_at.
        $rows = DB::table('transcription_segments as ts')
            ->join('transcriptions as t', 't.id', '=', 'ts.transcription_id')
            ->where('t.state', 'done')
            ->where('t.finished_at', '>=', $since)
            ->whereNotNull('ts.text')
            ->whereRaw("ts.text ~* ?", [$enRegex])
            ->orderBy('t.finished_at', 'desc')
            ->orderBy('ts.transcription_id')
            ->orderBy('ts.segment_index')
            ->get(['ts.transcription_id', 'ts.segment_index', 'ts.text', 't.finished_at']);

        $byTrans = [];
        foreach ($rows as $seg) {
            $tid = (int) $seg->transcription_id;
            $scored = $this->scoreSegment((string) $seg->text);
            if (!isset($byTrans[$tid])) {
                $byTrans[$tid] = [
                    'transcription_id' => $tid,
                    'finished_at' => $seg->finished_at,
                    'flagged_count' => 0,
                    'max_score' => 0.0,
                    'flagged_segment_indexes' => [],
                ];
            }
            if ($scored['score'] >= $threshold) {
                $byTrans[$tid]['flagged_count']++;
                $byTrans[$tid]['flagged_segment_indexes'][] = (int) $seg->segment_index;
            }
            if ($scored['score'] > $byTrans[$tid]['max_score']) {
                $byTrans[$tid]['max_score'] = $scored['score'];
            }
        }

        // Mantener solo las que tienen al menos un segmento flagged.
        return array_values(array_filter($byTrans, fn($r) => $r['flagged_count'] > 0));
    }

    /**
     * Marca una transcripción como needs_review. Idempotente: no pisa
     * status humano preexistente ('correct' / 'ignored').
     *
     * @return array{review: TranscriptionReview, action: string}
     */
    public function flagForReview(int $transcriptionId, int $reviewerId, array $score): array
    {
        $existing = TranscriptionReview::where('transcription_id', $transcriptionId)->first();

        if ($existing && in_array($existing->status, [
            TranscriptionReview::STATUS_CORRECT,
            TranscriptionReview::STATUS_IGNORED,
        ], true)) {
            return ['review' => $existing, 'action' => 'skipped_manual'];
        }

        $note = sprintf(
            'english_residual: score=%.2f | %d segs flagged | segs=%s | threshold=%.2f',
            $score['max_score'] ?? 0.0,
            $score['flagged_count'] ?? 0,
            $this->formatIndexes($score['flagged_segment_indexes'] ?? []),
            $this->defaultThreshold
        );

        $review = TranscriptionReview::updateOrCreate(
            ['transcription_id' => $transcriptionId],
            [
                'status' => TranscriptionReview::STATUS_NEEDS_REVIEW,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'notes' => $note,
            ]
        );

        $action = $existing ? 'updated' : 'created';
        return ['review' => $review, 'action' => $action];
    }

    /**
     * Helper: limita y formatea una lista de índices de segmentos.
     */
    private function formatIndexes(array $indexes, int $max = 5): string
    {
        $indexes = array_slice(array_values(array_unique($indexes)), 0, $max);
        return '[' . implode(',', $indexes) . ']'
            . (count($indexes) < count(array_unique($indexes)) ? '…' : '');
    }

    /**
     * Construye un regex "al menos una función EN como token independiente"
     * para usarlo como pre-filtro SQL barato. PostgreSQL usa \m/\M para
     * frontera de palabra (POSIX-style), no \b.
     *
     * OPTIMIZACIÓN: usar solo las 6 EN functions más comunes como
     * pre-filtro. El scoreSegment en PHP es quien decide el score final,
     * así que este regex es solo para ESCARTAR segmentos sin EN.
     */
    private function buildEnRegex(): string
    {
        $top = ['the','and','of','is','are','was'];
        $escaped = array_map(fn($w) => preg_quote($w, '/'), $top);
        return '\m(' . implode('|', $escaped) . ')\M';
    }
}
