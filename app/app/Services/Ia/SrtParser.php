<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Log;

/**
 * Parser de SRT estandar a segmentos con timestamps en segundos.
 *
 * Salida: [['index'=>1, 'start_seconds'=>0.64, 'end_seconds'=>6.56, 'text'=>'...'], ...]
 * Trunca segmentos > 500 chars (con warning) para no inflar la BD con basura.
 */
class SrtParser
{
    private const MAX_SEGMENT_CHARS = 500;

    public function parse(string $content): array
    {
        if ($content === '' || trim($content) === '') {
            return [];
        }

        $pattern = '/(?:^|\n)(\d+)\s*\n(\d{2}:\d{2}:\d{2}[,.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,.]\d{3})\s*\n([\s\S]*?)(?=\n\s*\n|\Z)/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $segments = [];
        foreach ($matches as $m) {
            $index = (int) $m[1];
            $startSeconds = $this->timeToSeconds($m[2]);
            $endSeconds = $this->timeToSeconds($m[3]);
            $text = trim(str_replace("\n", ' ', $m[4]));

            if (mb_strlen($text) > self::MAX_SEGMENT_CHARS) {
                Log::warning("SrtParser: segmento #{$index} excede " . self::MAX_SEGMENT_CHARS . " chars; truncando.");
                $text = mb_substr($text, 0, self::MAX_SEGMENT_CHARS);
            }

            $segments[] = [
                'index' => $index,
                'start_seconds' => $startSeconds,
                'end_seconds' => $endSeconds,
                'text' => $text,
            ];
        }

        return $segments;
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