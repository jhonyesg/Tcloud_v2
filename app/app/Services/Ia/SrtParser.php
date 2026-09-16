<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Log;

/**
 * Parser de SRT estandar a segmentos con timestamps en segundos.
 *
 * Salida: [['index'=>1, 'start_seconds'=>0.64, 'end_seconds'=>6.56, 'text'=>'...'], ...]
 *
 * El limite de longitud por segmento es configurable (`srt_max_segment_chars`,
 * 0 = sin limite). Estuvo fijado en 500 "para no inflar la BD con basura", pero
 * la premisa era falsa: la columna es `text` (ilimitada) y lo que se cortaba era
 * habla real de ~30s sin pausas — tipico de emisoras de radio. El texto cortado
 * dejaba de aparecer en las busquedas.
 */
class SrtParser
{
    /** Se mantiene como referencia historica del valor que causaba el recorte. */
    public const LEGACY_MAX_SEGMENT_CHARS = 500;

    public function __construct(private ?TranscriptorSettings $settings = null) {}

    public function parse(string $content): array
    {
        if ($content === '' || trim($content) === '') {
            return [];
        }

        $maxChars = $this->maxSegmentChars();

        $pattern = '/(?:^|\n)(\d+)\s*\n(\d{2}:\d{2}:\d{2}[,.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d{3})\s*\n([\s\S]*?)(?=\n\s*\n|\Z)/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $segments = [];
        $truncated = 0;
        $charsLost = 0;
        $longest = 0;

        foreach ($matches as $m) {
            $index = (int) $m[1];
            $startSeconds = $this->timeToSeconds($m[2]);
            $endSeconds = $this->timeToSeconds($m[3]);
            $text = trim(str_replace("\n", ' ', $m[4]));

            $len = mb_strlen($text);
            $longest = max($longest, $len);

            if ($maxChars > 0 && $len > $maxChars) {
                $truncated++;
                $charsLost += $len - $maxChars;
                $text = mb_substr($text, 0, $maxChars);
            }

            $segments[] = [
                'index' => $index,
                'start_seconds' => $startSeconds,
                'end_seconds' => $endSeconds,
                'text' => $text,
            ];
        }

        // UN aviso por SRT, con el agregado. Antes se emitia uno por segmento y
        // suponia el 15% de todo el log, ahogando cualquier otra señal.
        if ($truncated > 0) {
            Log::warning('SrtParser: segmentos truncados', [
                'segmentos' => $truncated,
                'de_total' => count($segments),
                'chars_perdidos' => $charsLost,
                'mas_largo' => $longest,
                'limite' => $maxChars,
            ]);
        }

        return $segments;
    }

    private function maxSegmentChars(): int
    {
        if ($this->settings !== null) {
            return $this->settings->int('srt_max_segment_chars');
        }

        return function_exists('config')
            ? (int) config('transcriptor.srt_max_segment_chars', 3000)
            : 3000;
    }

    /**
     * Convierte "HH:MM:SS,mmm" o "HH:MM:SS.mmm" a segundos (float).
     */
    private function timeToSeconds(string $time): float
    {
        $time = str_replace(',', '.', $time);
        $parts = explode(':', $time);
        if (count($parts) !== 3) {
            return 0.0;
        }
        return (float) (((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + (float) $parts[2]);
    }

    /**
     * Calcula la duración total en segundos a partir del último segmento.
     */
    public function calculateDuration(array $segments): ?int
    {
        if (empty($segments)) {
            return null;
        }
        $last = end($segments);
        return (int) ceil($last['end_seconds'] ?? 0.0);
    }

    /**
     * Estima el conteo de palabras a partir de los segmentos.
     */
    public function calculateWordCount(array $segments): int
    {
        $words = 0;
        foreach ($segments as $s) {
            $words += str_word_count($s['text'] ?? '');
        }
        return $words;
    }
}