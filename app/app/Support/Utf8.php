<?php

namespace App\Support;

/**
 * Reparación de doble codificación UTF-8.
 *
 * Un texto UTF-8 leído como Windows-1252 produce mojibake: "Atlántico" se
 * vuelve "AtlÃ¡ntico" (los bytes C3 A1 de la "á" leídos como dos
 * caracteres Latin-1). Es lo que pasa con algunos nombres de storage que
 * importaron de otra base.
 *
 * La reparación solo se aplica cuando hay una señal clara del patrón, para
 * no tocar texto que ya estaba bien.
 */
class Utf8
{
    /**
     * Repara un texto con doble codificación UTF-8, si la hay.
     *
     * Si el texto no presenta el patrón, se devuelve tal cual.
     */
    public static function repair(string $text): string
    {
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // La señal: un "Ã" o "Â" seguido de un carácter del rango de
        // puntuación de Windows-1252 (el byte suelto que dejó la lectura
        // Latin-1). Un texto español normal no produce esta secuencia.
        if (! preg_match('/[ÃÂ][\x{00A1}-\x{00BF}\x{00A0}]/u', $text)) {
            return $text;
        }

        // La reparación son dos pasos:
        //  1) De UTF-8 a ISO-8859-1: el texto se decodifica a sus caracteres
        //     (U+00C3, U+00A1) y se codifican como Latin-1, lo que devuelve
        //     los BYTES originales (C3 A1).
        //  2) Esos bytes se leen como UTF-8: "á".
        $bytes = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        $reparado = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');

        if (! mb_check_encoding($reparado, 'UTF-8')) {
            return $text;
        }

        // La vuelta por Latin-1 no puede representar todo (la "ñ" se
        // pierde). Si la reparación introduce interrogantes que el original
        // no tenía, es mejor quedarse con el original.
        if (! str_contains($text, '?') && str_contains($reparado, '?')) {
            return $text;
        }

        return $reparado;
    }
}
