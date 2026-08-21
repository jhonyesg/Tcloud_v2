<?php

use App\Models\Correction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Depuración de las reglas sueltas que quedaron activas tras
 * `corrections:quarantine-en-es`.
 *
 * El clasificador automático deja en el cubo REVISAR los pares ASCII->ASCII sin
 * marcadores ingleses, porque ahí conviven cosas que ninguna heurística separa:
 * typos españoles reales (`quando`->`cuando`, `echa`->`hecha`) y traducciones
 * cognadas (`national`->`nacional`, `hours`->`horas`). La distancia de edición
 * no sirve — `national`/`nacional` difieren en 1 carácter igual que un typo.
 *
 * Lo que sí separa el daño es la LONGITUD: una regla de 1-2 palabras dispara en
 * todo el corpus, mientras que una frase de 3+ palabras solo matchea una cadena
 * concreta y es inofensiva. Por eso aquí solo se tocan las de 1-2 palabras, con
 * la decisión tomada a mano sobre las 131 que había.
 *
 * Ejemplos del daño que provocaban aunque sean cognados: al sustituir palabra a
 * palabra sobre un segmento que YA está en inglés, el resultado sigue siendo
 * mezcla — "two hours" -> "two horas", "the class" -> "the clase",
 * "different things" -> "diferentes things". El diccionario corrige español;
 * no traduce.
 *
 * Reversible: la cuarentena es un cambio de risk_level y down() restaura tanto
 * eso como las 5 filas eliminadas.
 */
return new class extends Migration
{
    /**
     * Traducciones EN->ES de 1-2 palabras (incluidos cognados) + `ahorita`->`ahora`,
     * que no es un error sino un cambio de registro colombiano.
     */
    private const QUARANTINE_IDS = [
        127, 250, 251, 253, 260, 261, 268, 330, 331, 344, 356, 371, 373, 572,
        600, 607, 650, 651, 653, 660, 671, 763, 781, 799, 838, 917, 1017, 1208,
        1263, 1348, 1363, 1441, 1457, 1487, 1690, 1709, 1728, 1729, 1778, 1903,
        1922, 2004, 2006, 2017, 2021, 2055, 2177, 2184, 2203, 2205, 2228, 2263,
        2326, 2366, 2367, 2398, 2408, 2454, 2640, 2649, 2656, 2715, 2747, 2760,
        2773, 2781, 2789, 2791, 2817, 2826, 2828, 2841, 2870, 2902, 2919,
    ];

    /**
     * Basura: no traducen ni corrigen, solo rompen texto. `dise`->`de` era la
     * que convertía "al diseño" en "al deño" al combinarse con el bug de bytes.
     */
    private const GARBAGE = [
        ['id' => 370,  'wrong_text' => 'anders',       'correct_text' => 'de las',              'wrong_normalized' => 'anders',       'applies_count' => 2506],
        ['id' => 381,  'wrong_text' => 'dise',         'correct_text' => 'de',                  'wrong_normalized' => 'dise',         'applies_count' => 998],
        ['id' => 782,  'wrong_text' => 'comprometido', 'correct_text' => 'se han comprometido', 'wrong_normalized' => 'comprometido', 'applies_count' => 949],
        ['id' => 839,  'wrong_text' => 'Closet',       'correct_text' => 'Hola',                'wrong_normalized' => 'closet',       'applies_count' => 261],
        ['id' => 2022, 'wrong_text' => 'Next',         'correct_text' => 'Nex',                 'wrong_normalized' => 'next',         'applies_count' => 2563],
    ];

    /** Marca en `source` para poder distinguir/revertir lo que tocó esta migración. */
    private const TAG = '|q-suelta';

    public function up(): void
    {
        DB::table('corrections')
            ->whereIn('id', self::QUARANTINE_IDS)
            ->update([
                'risk_level' => Correction::RISK_HIGH,
                // varchar(64): recortar para no reventar el UPDATE.
                'source' => DB::raw("LEFT(COALESCE(source, '') || '" . self::TAG . "', 64)"),
                'updated_at' => now(),
            ]);

        DB::table('corrections')
            ->whereIn('id', array_column(self::GARBAGE, 'id'))
            ->delete();
    }

    public function down(): void
    {
        $tag = self::TAG;

        DB::table('corrections')
            ->whereIn('id', self::QUARANTINE_IDS)
            ->update([
                'risk_level' => Correction::RISK_LOW,
                'source' => DB::raw("REPLACE(source, '{$tag}', '')"),
                'updated_at' => now(),
            ]);

        foreach (self::GARBAGE as $row) {
            DB::table('corrections')->insert($row + [
                'status' => Correction::STATUS_APPROVED,
                'risk_level' => Correction::RISK_LOW,
                'proposed_by' => 1,
                'approved_by' => 1,
                'approved_at' => now(),
                'source' => 'restaurada-por-rollback',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
