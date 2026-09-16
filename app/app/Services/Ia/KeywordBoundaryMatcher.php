<?php

namespace App\Services\Ia;

/**
 * Matching de keywords por palabra completa (avisos-keyword-word-boundary).
 *
 * Reemplaza el matching por subcadena cruda (str_contains) que producía
 * falsos positivos del tipo "petro" ∈ "petróleo" / "Petromil". Una keyword
 * solo matchea cuando la posición del match está delimitada por fronteras
 * de palabra: el carácter anterior al inicio y el posterior al final NO son
 * letra ni dígito Unicode.
 *
 * La lógica de bordes replica el prior art del módulo de correcciones
 * (CorrectionService::isWordCharAt): el texto se recorta por CARÁCTER UTF-8
 * completo (no por byte — el byte líder de 'á' es 0xC3 y ctype_alnum a
 * secas contaría una letra acentuada como frontera).
 *
 * El texto de entrada debe venir normalizado igual que el resto del motor
 * (Keyword::asciiLower). El pre-filtro str_contains se conserva como
 * fast-path: es un superset barato de la frontera.
 */
class KeywordBoundaryMatcher
{
    /** ¿Es letra o dígito Unicode el carácter en la posición byte $idx? */
    public static function isWordCharAt(string $text, int $idx): bool
    {
        $len = strlen($text);
        if ($idx < 0 || $idx >= $len) {
            return false;
        }

        $byte = $text[$idx];
        $ord = ord($byte);

        // ASCII: decisión directa, sin coste extra en el 95% de los bordes.
        if ($ord < 0x80) {
            return ($ord >= 0x30 && $ord <= 0x39)   // 0-9
                || ($ord >= 0x41 && $ord <= 0x5A)   // A-Z
                || ($ord >= 0x61 && $ord <= 0x7A);  // a-z
        }

        // Multibyte: recortar el carácter completo. Retrocedemos hasta el
        // byte líder (10xxxxxx = continuación) y tomamos la secuencia entera.
        $start = $idx;
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        $char = mb_substr(mb_substr($text, $start, 1), 0, 1);

        return $char !== '' && (bool) preg_match('/[\p{L}\p{N}]/u', $char);
    }

    /**
     * ¿Matchea $needle como palabra completa en $haystack?
     * Ambos deben venir normalizados (Keyword::asciiLower).
     */
    public static function matchesWord(string $haystackNorm, string $needleNorm): bool
    {
        return self::firstPosition($haystackNorm, $needleNorm) !== null;
    }

    /**
     * Cuenta las apariciones de $needle con frontera de palabra.
     */
    public static function countOccurrences(string $haystackNorm, string $needleNorm): int
    {
        if ($needleNorm === '' || $haystackNorm === '') {
            return 0;
        }

        $count = 0;
        $hayLen = strlen($haystackNorm);
        $needleLen = strlen($needleNorm);
        $offset = 0;

        while ($offset <= $hayLen - $needleLen) {
            $found = strpos($haystackNorm, $needleNorm, $offset);
            if ($found === false) {
                break;
            }

            if (self::isWordCharAt($haystackNorm, $found - 1)
                || self::isWordCharAt($haystackNorm, $found + $needleLen)) {
                $offset = $found + 1;
                continue;
            }

            $count++;
            $offset = $found + $needleLen;
        }

        return $count;
    }

    /**
     * Posición (en BYTES, sobre el haystack normalizado) de la primera
     * aparición con frontera, o null si no hay. Usado por buildSnippet.
     */
    public static function firstPosition(string $haystackNorm, string $needleNorm): ?int
    {
        if ($needleNorm === '' || $haystackNorm === '') {
            return null;
        }

        $hayLen = strlen($haystackNorm);
        $needleLen = strlen($needleNorm);
        $offset = 0;

        while ($offset <= $hayLen - $needleLen) {
            $found = strpos($haystackNorm, $needleNorm, $offset);
            if ($found === false) {
                return null;
            }

            if (!self::isWordCharAt($haystackNorm, $found - 1)
                && !self::isWordCharAt($haystackNorm, $found + $needleLen)) {
                return $found;
            }

            $offset = $found + 1;
        }

        return null;
    }
}