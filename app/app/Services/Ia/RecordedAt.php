<?php

namespace App\Services\Ia;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 2.1.
 *
 * Resolución determinística de la **fecha del programa** (`recorded_at`)
 * a partir de señales disponibles:
 *
 *   1. Nombre del archivo (regex `*_DDMMYYYY_HHMMSS.ext`). Cubre ~90% de
 *      archivos de TCloud (verificado: 1.628.783 / 1.810.353 archivos).
 *   2. `files.file_modified_at` (fallback cuando el nombre no es parseable).
 *   3. `transcriptions.finished_at` (último recurso: el audio al menos fue
 *      procesado en esa fecha).
 *   4. NULL — caso degenerado, requiere atención admin.
 *
 * La cascada es la misma que vive en la migración de backfill
 * (`2026_09_10_130000_add_recorded_at_to_transcriptions_table.php`); este
 * helper es la versión PHP reutilizable en runtime (model boot, jobs, etc.).
 */
class RecordedAt
{
    /**
     * Regex que captura el segmento `_<DD><MM><AAAA>_<HH><MM><SS>.ext` al
     * final del nombre. Las clases de caracteres limitan cada componente a
     * rangos válidos (01-31 día, 01-12 mes, 19|20 año, 00-23 hora, etc.).
     */
    private const FILENAME_REGEX =
        '/_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])_'.
        '([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.[a-zA-Z0-9]+$/';

    /**
     * Parsea el nombre del archivo buscando el patrón `*_DDMMYYYY_HHMMSS.ext`.
     * Retorna CarbonImmutable en zona horaria local del servidor, o null si
     * el nombre no matchea.
     */
    public static function fromFilename(?string $name): ?CarbonImmutable
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (!preg_match(self::FILENAME_REGEX, $name, $m)) {
            return null;
        }
        // Grupos del regex:
        //   $m[1] = DD, $m[2] = MM, $m[3] = YYYY completo,
        //   $m[4] = siglo (19|20), $m[5] = HH, $m[6] = MI, $m[7] = SS.
        // El grupo extra $m[4] viene de `(19|20)` dentro de `((19|20)[0-9][0-9])`.
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        $hour = (int) $m[5];
        $minute = (int) $m[6];
        $second = (int) $m[7];

        // Validación semántica (rechaza 2026-02-31, etc.).
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Cascada completa. El primer valor no-nulo gana.
     *
     * @param string|null                  $name          nombre del archivo
     * @param CarbonImmutable|string|null  $fileModified  timestamp del FS
     * @param CarbonImmutable|string|null  $finishedAt    cuando terminó el transcriptor
     */
    public static function resolve(
        ?string $name,
        $fileModified = null,
        $finishedAt = null,
    ): ?CarbonImmutable {
        $fromName = self::fromFilename($name);
        if ($fromName !== null) {
            return $fromName;
        }
        if ($fileModified !== null) {
            return self::normalize($fileModified);
        }
        if ($finishedAt !== null) {
            return self::normalize($finishedAt);
        }
        return null;
    }

    private static function normalize($value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof Carbon) {
            return CarbonImmutable::instance($value);
        }
        return CarbonImmutable::parse((string) $value);
    }
}
