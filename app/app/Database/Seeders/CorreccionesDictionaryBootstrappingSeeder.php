<?php

namespace App\Database\Seeders;

use App\Models\Correction;
use App\Models\User;
use App\Services\Ia\CorrectionService;
use Illuminate\Database\Seeder;

/**
 * Puebla el diccionario de correcciones con 86 pares detectados
 * en el análisis del corpus del 2026-07-29 (628.095 segmentos
 * de transcripción creados ese día).
 *
 *   · 50 pares estructurales EN↔ES (48 approved + 2 pending)
 *   · 36 typos fonéticos sin tilde (24 approved + 12 pending)
 *
 * Idempotente: usa `upsertApproved()` que actualiza el
 * `correct_text` si la fila approved existe por `wrong_normalized`,
 * y `propose()` que crea o actualiza pending.
 *
 * Cada fila lleva `source='bootstrapping-2026-07-29'` para permitir
 * rollback selectivo:
 *   UPDATE corrections SET status='rejected' WHERE source='bootstrapping-2026-07-29';
 *
 * Ejecutar manualmente:
 *   php artisan db:seed --class=CorreccionesDictionaryBootstrappingSeeder
 */
class CorreccionesDictionaryBootstrappingSeeder extends Seeder
{
    public const SOURCE = 'bootstrapping-2026-07-29';

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command?->error('No hay usuario admin. Crear un admin antes de cargar el diccionario.');
            return;
        }

        $service = app(CorrectionService::class);

        $approved = [
            // GRUPO A · pares estructurales EN→ES (48)
            ['in the world',         'en el mundo'],
            ['of the world',         'del mundo'],
            ['at the end',           'al final'],
            ['all the time',         'todo el tiempo'],
            ['at the time',          'en ese momento'],
            ['of the people',        'de la gente'],
            ['of the year',          'del año'],
            ['at the moment',        'en este momento'],
            ['of the government',    'del gobierno'],
            ['in the history',       'en la historia'],
            ['of the day',           'del día'],
            ['in the region',        'en la región'],
            ['in the department',    'en el departamento'],
            ['of the president',     'del presidente'],
            ['in the city',          'en la ciudad'],
            ['of the night',         'de la noche'],
            ['of the department',    'del departamento'],
            ['and the people',       'y la gente'],
            ['in the market',        'en el mercado'],
            ['in the zone',          'en la zona'],
            ['of the community',     'de la comunidad'],
            ['of the state',         'del estado'],
            ['of the nation',        'de la nación'],
            ['of the history',       'de la historia'],
            ['at the same time',     'al mismo tiempo'],
            ['of the region',        'de la región'],
            ['in the territory',     'en el territorio'],
            ['in the area',          'en el área'],
            ['for the people',       'para la gente'],
            ['of the market',        'del mercado'],
            ['in the morning',       'en la mañana'],
            ['of the territory',     'del territorio'],
            ['with the people',      'con la gente'],
            ['and the government',   'y el gobierno'],
            ['in the country',       'en el país'],
            ['by the way',           'por cierto'],
            ['of the society',       'de la sociedad'],
            ['at the university',    'en la universidad'],
            ['with the community',   'con la comunidad'],
            ['for the moment',       'por el momento'],
            ['of the area',          'del área'],
            ['of the country',       'del país'],
            ['with the government',  'con el gobierno'],
            ['in the government',    'en el gobierno'],
            ['for the government',   'por el gobierno'],
            ['at the hospital',      'en el hospital'],
            ['at the beginning',     'al principio'],
            ['in the meantime',      'mientras tanto'],

            // GRUPO B · typos fonéticos sin tilde (24)
            ['atencion',             'atención'],
            ['ejecution',            'ejecución'],
            ['incumpliment',         'incumplimiento'],
            ['incumpliments',        'incumplimientos'],
            ['opinion',              'opinión'],
            ['emision',              'emisión'],
            ['comision',             'comisión'],
            ['direccion',            'dirección'],
            ['organizacion',         'organización'],
            ['diagnostico',          'diagnóstico'],
            ['pronostico',           'pronóstico'],
            ['turistico',            'turístico'],
            ['turistica',            'turística'],
            ['artistico',            'artístico'],
            ['artistica',            'artística'],
            ['caracteristica',       'característica'],
            ['caracteristicas',      'características'],
            ['publicamente',         'públicamente'],
            ['rapidamente',          'rápidamente'],
            ['unicamente',           'únicamente'],
            ['logicamente',          'lógicamente'],
            ['basicamente',          'básicamente'],
            ['practicamente',        'prácticamente'],
            ['epoca',                'época'],
            ['unico',                'único'],
            ['publico',              'público'],
            ['comico',               'cómico'],
            ['magico',               'mágico'],
            ['tipico',               'típico'],
            ['clasico',              'clásico'],
            ['clasica',              'clásica'],
            ['paralisis',            'parálisis'],
            ['hipotesis',            'hipótesis'],
            ['metafora',             'metáfora'],
        ];

        $pending = [
            // GRUPO A · 2 pending (baja freq, contexto-dependiente)
            ['over and over',        'una y otra vez'],
            ['day and night',        'día y noche'],

            // GRUPO B · 12 pending (palabras con contexto válido sin tilde)
            ['recursion',            'recursión'],
            ['version',              'versión'],
            ['religion',             'religión'],
            ['region',               'región'],
            ['sesion',               'sesión'],
            ['ocasion',              'ocasión'],
            ['publica',              'pública'],
            ['unica',                'única'],
            ['musica',               'música'],
            ['magica',               'mágica'],
            ['artisticamente',       'artísticamente'],
            ['caracteristico',       'característico'],
        ];

        $countApproved = 0;
        foreach ($approved as [$wrong, $correct]) {
            $correction = $service->upsertApproved($wrong, $correct, $admin);
            $correction->source = self::SOURCE;
            $correction->save();
            $countApproved++;
            $this->command?->info(sprintf('  APPROVED #%d: "%s" → "%s"', $correction->id, $wrong, $correct));
        }

        $countPending = 0;
        foreach ($pending as [$wrong, $correct]) {
            $correction = $service->propose($admin, $wrong, $correct);
            $correction->source = self::SOURCE;
            $correction->save();
            $countPending++;
            $this->command?->info(sprintf('  PENDING  #%d: "%s" → "%s"', $correction->id, $wrong, $correct));
        }

        $total = Correction::where('source', self::SOURCE)->count();
        $approvedCount = Correction::where('source', self::SOURCE)
            ->where('status', Correction::STATUS_APPROVED)->count();
        $pendingCount = Correction::where('source', self::SOURCE)
            ->where('status', Correction::STATUS_PENDING)->count();

        $this->command?->info("");
        $this->command?->info("Resumen bootstrap " . self::SOURCE . ":");
        $this->command?->info("  approved: {$approvedCount}");
        $this->command?->info("  pending : {$pendingCount}");
        $this->command?->info("  total   : {$total}");
    }
}