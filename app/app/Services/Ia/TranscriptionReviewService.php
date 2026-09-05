<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Transcription;
use App\Models\TranscriptionReview;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TranscriptionReviewService
{
    public const MODE_REQUESTED = 'requested';
    public const MODE_COMPLETED = 'completed';
    public const MODE_LATEST = 'latest';
    public const MODE_SENSITIVE = 'sensitive';

    public const MODES = [
        self::MODE_REQUESTED,
        self::MODE_COMPLETED,
        self::MODE_SENSITIVE,
    ];

    /** SQLSTATE de Postgres para "query canceled" (statement_timeout agotado). */
    private const SQLSTATE_QUERY_CANCELED = '57014';

    /** Transcripciones candidatas por listado (latest/sensitive/completed). */
    private const LIST_LIMIT = 10;

    public function normalizeMode(string $mode): string
    {
        if ($mode === self::MODE_LATEST) {
            return self::MODE_REQUESTED;
        }

        return in_array($mode, self::MODES, true)
            ? $mode
            : self::MODE_REQUESTED;
    }

    public function list(string $mode = self::MODE_LATEST): array
    {
        $mode = $this->normalizeMode($mode);

        $query = Transcription::query()
            ->where('state', Transcription::STATE_DONE)
            ->with('file:id,name', 'review:id,transcription_id,status,reviewed_by,reviewed_at,notes')
            ->limit(self::LIST_LIMIT);

        if ($mode === self::MODE_REQUESTED) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $query->orderByRaw('finished_at DESC NULLS LAST')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        if ($mode === self::MODE_SENSITIVE) {
            // Acotado (change: corrections-manual-only-and-context-search):
            // el whereExists con position() contra 2.3k reglas approved medium/high
            // sobre el histórico completo no termina. El filtro sensibles solo
            // decide QUÉ 10 transcripciones entran a la lista, así que se aplica
            // sobre las candidatas ya resueltas, bajo statement_timeout.
            $candidates = (clone $query)->get();

            if ($candidates->isEmpty()) {
                return [];
            }

            $sensitiveIds = $this->filterSensitiveIds($candidates->pluck('id')->all());

            return $this->buildListItems(
                $candidates->filter(fn (Transcription $t) => in_array($t->id, $sensitiveIds, true))->values(),
                $mode
            );
        }

        return $this->buildListItems($query->get(), $mode);
    }

    /**
     * Filtra IDs de transcripciones que tienen al menos un segmento cuyo
     * text_raw contiene una regla approved medium/high. Bajo transaction con
     * statement_timeout: en timeout devuelve las que ya matchearon (parcial)
     * — mejor una respuesta degradada que un 504 de nginx.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function filterSensitiveIds(array $ids): array
    {
        $timeoutMs = (int) config('corrections.review_sensitive.timeout_ms', 10000);

        try {
            return DB::transaction(function () use ($ids, $timeoutMs) {
                DB::statement("SET LOCAL statement_timeout = '{$timeoutMs}ms'");

                return (array) DB::table('transcription_segments as sensitive_segments')
                    ->select('sensitive_segments.transcription_id')
                    ->whereIn('sensitive_segments.transcription_id', $ids)
                    ->join('corrections as sensitive_corrections', function ($join) {
                        $join->where('sensitive_corrections.status', Correction::STATUS_APPROVED)
                            ->whereIn('sensitive_corrections.risk_level', [
                                Correction::RISK_MEDIUM,
                                Correction::RISK_HIGH,
                            ])
                            ->whereRaw("position(lower(sensitive_corrections.wrong_normalized) in lower(sensitive_segments.text_raw)) > 0");
                    })
                    ->distinct()
                    ->pluck('sensitive_segments.transcription_id')
                    ->all();
            });
        } catch (QueryException $e) {
            if (!$this->isTimeout($e)) {
                throw $e;
            }

            return [];
        }
    }

    private function isTimeout(QueryException $e): bool
    {
        return $e->getCode() === self::SQLSTATE_QUERY_CANCELED
            || str_contains($e->getMessage(), 'statement timeout');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Transcription>  $transcriptions
     * @return array<int, array<string, mixed>>
     */
    private function buildListItems($transcriptions, string $mode): array
    {
        if ($transcriptions->isEmpty()) {
            return [];
        }

        $ids = $transcriptions->pluck('id')->all();
        $changedCounts = DB::table('transcription_segments')
            ->select('transcription_id', DB::raw('count(*) as changed_segments_count'))
            ->whereIn('transcription_id', $ids)
            ->whereColumn('text_raw', '!=', 'text')
            ->groupBy('transcription_id')
            ->pluck('changed_segments_count', 'transcription_id');

        // Conteo de matches sensibles bajo el mismo statement_timeout: es la
        // misma familia de query position() y puede ser igual de pesada.
        $sensitiveCounts = $this->sensitiveCounts($ids);

        return $transcriptions->map(function (Transcription $transcription) use ($changedCounts, $sensitiveCounts, $mode): array {
            return [
                'id' => $transcription->id,
                'file_name' => $transcription->file?->name ?? ('Transcription #' . $transcription->id),
                'finished_at' => $transcription->finished_at?->toIso8601String(),
                'created_at' => $transcription->created_at?->toIso8601String(),
                'recency_mode' => $mode,
                'segments_count' => (int) $transcription->segments()->count(),
                'changed_segments_count' => (int) ($changedCounts[$transcription->id] ?? 0),
                'sensitive_matches_count' => $sensitiveCounts['counts'][$transcription->id] ?? 0,
                'sensitive_degraded' => $sensitiveCounts['degraded'],
                'review' => $this->reviewPayload($transcription->review),
            ];
        })->all();
    }

    /**
     * Conteo de matches sensibles por transcripción. En timeout retorna
     * counts vacíos + degraded=true para que la UI distinga el caso.
     *
     * @param array<int, int> $ids
     * @return array{counts: array<int, int>, degraded: bool}
     */
    private function sensitiveCounts(array $ids): array
    {
        $timeoutMs = (int) config('corrections.review_sensitive.timeout_ms', 10000);

        try {
            $counts = DB::transaction(function () use ($ids, $timeoutMs) {
                DB::statement("SET LOCAL statement_timeout = '{$timeoutMs}ms'");

                return DB::table('transcription_segments as count_segments')
                    ->select('count_segments.transcription_id', DB::raw('count(*) as sensitive_matches_count'))
                    ->whereIn('count_segments.transcription_id', $ids)
                    ->join('corrections as count_corrections', function ($join) {
                        $join->where('count_corrections.status', Correction::STATUS_APPROVED)
                            ->whereIn('count_corrections.risk_level', [
                                Correction::RISK_MEDIUM,
                                Correction::RISK_HIGH,
                            ])
                            ->whereRaw("position(lower(count_corrections.wrong_normalized) in lower(count_segments.text_raw)) > 0");
                    })
                    ->groupBy('count_segments.transcription_id')
                    ->pluck('sensitive_matches_count', 'count_segments.transcription_id');
            });

            return ['counts' => $counts->all(), 'degraded' => false];
        } catch (QueryException $e) {
            if (!$this->isTimeout($e)) {
                throw $e;
            }

            return ['counts' => [], 'degraded' => true];
        }
    }

    public function detail(int $id): array
    {
        $transcription = Transcription::query()
            ->where('state', Transcription::STATE_DONE)
            ->with('file:id,name', 'review')
            ->findOrFail($id);

        $rules = Correction::approved()
            ->whereNotNull('wrong_normalized')
            ->orderByRaw('length(wrong_normalized) desc')
            ->get([
                'id', 'wrong_text', 'correct_text', 'wrong_normalized',
                'risk_level', 'source',
            ]);

        $segments = $transcription->segments()
            ->orderBy('segment_index')
            ->get([
                'id', 'segment_index', 'start_seconds', 'end_seconds',
                'text_raw', 'text',
            ]);

        $changed = [];
        foreach ($segments as $index => $segment) {
            if ((string) $segment->text_raw === (string) $segment->text) {
                continue;
            }

            $matches = $this->matchesForSegment($segment->text_raw, $segment->text, $rules);
            $changed[] = [
                'id' => $segment->id,
                'segment_index' => $segment->segment_index,
                'start_seconds' => (float) $segment->start_seconds,
                'end_seconds' => (float) $segment->end_seconds,
                'text_raw' => $segment->text_raw,
                'text' => $segment->text,
                'matches' => $matches,
                'previous_segment' => $this->neighbor($segments->get($index - 1)),
                'next_segment' => $this->neighbor($segments->get($index + 1)),
            ];
        }

        return [
            'id' => $transcription->id,
            'file_name' => $transcription->file?->name ?? ('Transcription #' . $transcription->id),
            'created_at' => $transcription->created_at?->toIso8601String(),
            'finished_at' => $transcription->finished_at?->toIso8601String(),
            'language' => $transcription->language,
            'duration_seconds' => $transcription->duration_seconds,
            'word_count' => $transcription->word_count,
            'srt_content' => $transcription->srt_content,
            'review' => $this->reviewPayload($transcription->review),
            'changed_segments' => $changed,
        ];
    }

    public function updateReview(Transcription $transcription, string $status, ?string $notes, int $reviewerId): TranscriptionReview
    {
        return TranscriptionReview::updateOrCreate(
            ['transcription_id' => $transcription->id],
            [
                'status' => $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'notes' => $notes,
            ]
        );
    }

    private function matchesForSegment(string $raw, string $corrected, $rules): array
    {
        $matches = [];
        foreach ($rules as $rule) {
            if ($rule->wrong_normalized === '') {
                continue;
            }

            $pattern = '/(?<![\pL\pN_])' . preg_quote($rule->wrong_normalized, '/') . '(?![\pL\pN_])/iu';
            if (!preg_match($pattern, $raw)) {
                continue;
            }

            $expected = preg_replace($pattern, $rule->correct_text, $raw);
            $confidence = $expected === $corrected ? 'exact' : 'candidate';
            $matches[] = [
                'correction_id' => $rule->id,
                'wrong_text' => $rule->wrong_text,
                'correct_text' => $rule->correct_text,
                'risk_level' => $rule->risk_level,
                'source' => $rule->source,
                'confidence' => $confidence,
            ];
        }

        return $matches;
    }

    private function neighbor($segment): ?array
    {
        if (!$segment) {
            return null;
        }

        return [
            'segment_index' => $segment->segment_index,
            'start_seconds' => (float) $segment->start_seconds,
            'end_seconds' => (float) $segment->end_seconds,
            'text' => $segment->text,
        ];
    }

    private function reviewPayload(?TranscriptionReview $review): array
    {
        return [
            'status' => $review?->status ?? TranscriptionReview::STATUS_PENDING,
            'reviewed_at' => $review?->reviewed_at?->toIso8601String(),
            'notes' => $review?->notes,
        ];
    }
}
