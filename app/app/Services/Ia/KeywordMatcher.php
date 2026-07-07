<?php

namespace App\Services\Ia;

use App\Models\Keyword;
use App\Models\KeywordMatch;
use App\Models\Transcription;
use App\Models\User;
use App\Models\UserAlertsInteligente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matching de keywords contra los segmentos (text corregido) de una
 * transcripción recién completada. Implementa coalescing (1 email por
 * usuario con todos sus matches) e idempotencia (no duplica matches si
 * se re-ejecuta para la misma transcripción).
 */
class KeywordMatcher
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    /**
     * Ejecuta el matching para una Transcription y envía alertas.
     * Devuelve el número de usuarios a los que se les envió alerta.
     */
    public function run(Transcription $transcription): int
    {
        // Idempotencia: si ya hay matches para esta transcripción, no reprocesar.
        $alreadyMatched = KeywordMatch::where('transcription_id', $transcription->id)->exists();
        if ($alreadyMatched) {
            return 0;
        }

        $segments = $transcription->segments()
            ->orderBy('segment_index')
            ->get(['id', 'segment_index', 'text', 'start_seconds']);

        if ($segments->isEmpty()) {
            return 0;
        }

        // Usuarios con el módulo activo.
        $users = User::whereHas('alertsInteligente', function ($q) {
            $q->where('enabled', true);
        })->with(['userKeywords:id,normalized', 'alertsInteligente'])->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $alertsSent = 0;

        foreach ($users as $user) {
            $keywords = $user->userKeywords->pluck('normalized')->filter()->unique()->values();
            if ($keywords->isEmpty()) {
                continue;
            }

            $matchesForUser = [];
            $now = now();

            foreach ($segments as $segment) {
                $segmentText = Keyword::asciiLower((string) $segment->text);
                foreach ($keywords as $keywordNorm) {
                    if ($keywordNorm !== '' && str_contains($segmentText, $keywordNorm)) {
                        $snippet = $this->buildSnippet($segment->text, $keywordNorm);
                        $minuteLabel = $this->secondsToHms((float) $segment->start_seconds);

                        KeywordMatch::create([
                            'transcription_id' => $transcription->id,
                            'keyword_id' => Keyword::where('normalized', $keywordNorm)->value('id'),
                            'segment_id' => $segment->id,
                            'user_id' => $user->id,
                            'snippet' => $snippet,
                            'matched_at' => $now,
                        ]);

                        $matchesForUser[] = [
                            'keyword' => $keywordNorm,
                            'segment_index' => $segment->segment_index,
                            'minute_label' => $minuteLabel,
                            'snippet' => $snippet,
                        ];
                    }
                }
            }

            if (!empty($matchesForUser)) {
                $this->dispatcher->send($user, $transcription, $matchesForUser);
                $alertsSent++;
            }
        }

        return $alertsSent;
    }

    /**
     * Construye un snippet de ~200 chars alrededor del match.
     */
    private function buildSnippet(string $text, string $keyword): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $len = mb_strlen($text);
        if ($len <= 200) {
            return $text;
        }

        $pos = mb_stripos($text, $keyword);
        if ($pos === false) {
            return mb_substr($text, 0, 200);
        }

        $start = max(0, $pos - 80);
        $snippet = mb_substr($text, $start, 200);
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if ($start + 200 < $len) {
            $snippet = $snippet . '...';
        }
        return $snippet;
    }

    private function secondsToHms(float $seconds): string
    {
        $total = (int) floor($seconds);
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}