<?php

namespace App\Database\Seeders;

use App\Models\Correction;
use App\Models\User;
use App\Services\Ia\CorrectionService;
use Illuminate\Database\Seeder;

/**
 * Segunda oleada de correcciones pendientes detectadas en el corpus
 * del 2026-07-29 (después del primer seeder v1).
 *
 * En esta oleada todas las reglas entran como `pending` para revisión
 * admin. Se enfoca en:
 *  · Truncamientos -mente (supuestament → supuestamente)
 *  · Acentos en adverbios cortos (aca, aqui, alli, recien)
 *  · Confusiones fonéticas coloquiales (echo/hecho, sierto/cierto)
 *  · Variantes estructurales EN→ES adicionales
 *  · Verbos irregulares mal conjugados (morido/muerto, pediendo/pidiendo)
 *
 * Idempotente: usa `propose()` que crea o actualiza pending por wrong_normalized.
 *
 * Ejecutar:
 *   php artisan db:seed --class='App\Database\Seeders\CorreccionesDictionaryPendingSeeder' --force
 */
class CorreccionesDictionaryPendingSeeder extends Seeder
{
    public const SOURCE = 'pending-round2-2026-07-29';

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command?->error('No hay usuario admin. Crear un admin antes de cargar el diccionario.');
            return;
        }

        $service = app(CorrectionService::class);

        $pendientes = [
            // === Truncamientos -mente (typos claros, alta frecuencia) ===
            ['solament',          'solamente'],
            ['realment',          'realmente'],
            ['precisament',       'precisamente'],
            ['obviament',         'obviamente'],
            ['exactament',        'exactamente'],
            ['efectivament',      'efectivamente'],
            ['evidentement',      'evidentemente'],
            ['supuestament',      'supuestamente'],
            ['particularment',    'particularmente'],
            ['generalment',       'generalmente'],
            ['aparentement',      'aparentemente'],
            ['verdaderament',     'verdaderamente'],
            ['practicament',      'prácticamente'],
            ['basicament',        'básicamente'],
            ['ciertament',        'ciertamente'],
            ['historicament',     'históricamente'],
            ['specificamente',    'específicamente'],
            ['automaticament',    'automáticamente'],
            ['cientificament',    'científicamente'],

            // === Acentos faltantes (palabras comunes) ===
            ['mas',               'más'],     // 16442x - WARNING: 'mas' como conj puede ser válido
            ['aca',               'acá'],     // 13650x
            ['recien',            'recién'],  // 1563x
            ['aqui',              'aquí'],    // 1022x
            ['alli',              'allí'],    // 761x
            ['ahorita',           'ahora'],   // 1308x - coloquial colombiano
            ['ademas',            'además'],  // 3x
            ['despues',           'después'], // ya estaba en GRUPO A? No, verificar

            // === 'echo' mal usado en lugar de 'hecho' ===
            ['echa',              'hecha'],   // 6546x
            ['echos',             'hechos'],  // 2812x
            ['echas',             'hechas'],  // 524x
            ['e echo',            'hecho'],
            ['en echo',           'en hecho'],
            ['a echo',            'a hecho'],
            ['de echo',           'de hecho'],
            ['es echo',           'es hecho'],
            ['echo de menos',     'echado de menos'],

            // === Confusiones coloquiales colombianas ===
            ['sierto',            'cierto'],  // 109x
            ['antier',            'anteayer'],// 26x
            ['apesar',            'a pesar'], // 6x
            ['nadien',            'nadie'],   // 15x
            ['jarta',             'harta'],   // 7x

            // === Verbos irregulares mal conjugados ===
            ['morido',            'muerto'],  // 8x - 'ha morido' → 'ha muerto'
            ['pediendo',          'pidiendo'],// 3x

            // === Variantes estructurales EN→ES adicionales ===
            ['in this moment',    'en este momento'],
            ['at this moment',    'en este momento'],
            ['in that moment',    'en ese momento'],
            ['at that moment',    'en ese momento'],
            ['in the system',     'en el sistema'],
            ['in the building',   'en el edificio'],
            ['to the world',      'al mundo'],
            ['for the world',     'para el mundo'],
            ['on the world',      'en el mundo'],
            ['with the world',    'con el mundo'],
            ['from the world',    'del mundo'],
            ['over the world',    'sobre el mundo'],
            ['around the world',  'por todo el mundo'],
        ];

        $countAdded = 0;
        $countSkipped = 0;
        foreach ($pendientes as [$wrong, $correct]) {
            // Si ya existe como approved, no crear pending duplicado.
            $alreadyApproved = Correction::approved()
                ->where('wrong_normalized', Correction::normalize($wrong))
                ->exists();

            if ($alreadyApproved) {
                $countSkipped++;
                continue;
            }

            $correction = $service->propose($admin, $wrong, $correct);
            $correction->source = self::SOURCE;
            $correction->save();
            $countAdded++;
            $this->command?->info(sprintf('  PENDING  #%d: "%s" → "%s"', $correction->id, $wrong, $correct));
        }

        $total = Correction::where('source', self::SOURCE)->count();
        $this->command?->info("");
        $this->command?->info("Resumen " . self::SOURCE . ":");
        $this->command?->info("  nuevas pendientes: {$countAdded}");
        $this->command?->info("  skipped (ya approved): {$countSkipped}");
        $this->command?->info("  total en este source: {$total}");
    }
}