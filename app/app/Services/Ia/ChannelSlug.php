<?php

namespace App\Services\Ia;

/**
 * Deriva el slug del canal a partir del nombre de archivo de la grabación.
 *
 * En el corpus conviven dos convenciones de nombre, y hay 34.497 transcripciones
 * con la segunda, así que no basta con cortar por el primer guion bajo:
 *
 *   teleisla_13082026_073002.mp4           -> teleisla
 *   15_abc_atlantico_19072026_154003.mp3   -> abc_atlantico
 *
 * La marca fiable es la FECHA: un token de exactamente 8 dígitos (ddmmaaaa).
 * Todo lo que va antes, quitando el número de orden inicial si lo hay, es el
 * canal. No se usa la tabla `canales`: es otro subsistema (24 slots de grabación
 * con nombres tipo `Puntual_05`) que no casa con estos archivos.
 */
class ChannelSlug
{
    public static function fromFilename(?string $originalName): ?string
    {
        $name = trim((string) $originalName);

        if ($name === '') {
            return null;
        }

        // Quitar la extensión (puede no haberla).
        $name = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?? $name;

        // Quitar el número de orden inicial: "15_abc_atlantico" -> "abc_atlantico".
        $name = preg_replace('/^\d+_/', '', $name) ?? $name;

        $parts = preg_split('/[_\-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slug = [];

        foreach ($parts as $part) {
            // La fecha marca el final del nombre del canal.
            if (preg_match('/^\d{8}$/', $part)) {
                break;
            }

            // Un token puramente numérico que no es la fecha tampoco forma parte
            // del canal (números de corte, horas sueltas).
            if (ctype_digit($part)) {
                continue;
            }

            $slug[] = mb_strtolower($part);
        }

        if (empty($slug)) {
            return null;
        }

        return implode('_', $slug);
    }
}
