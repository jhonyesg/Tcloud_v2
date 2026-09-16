<?php

namespace App\Database\Seeders;

use App\Models\Correction;
use App\Models\User;
use App\Services\Ia\CorrectionService;
use Illuminate\Database\Seeder;

/**
 * Tercera oleada de correcciones pendientes, basada en análisis del
 * corpus histórico (últimos 30 días, ~9.3M segmentos).
 *
 * Foco: adjetivos técnicos del español que el ASR omite la tilde
 * (médica, política, económica, clínica, electrónica, etc.) + frases
 * estructurales EN→ES adicionales detectadas en días anteriores.
 *
 * Todas como `pending` (no approved) para revisión admin antes de
 * aplicar retroactivamente.
 *
 * Ejecutar:
 *   php artisan db:seed --class='App\Database\Seeders\CorreccionesDictionaryPendingSeeder3' --force
 */
class CorreccionesDictionaryPendingSeeder3 extends Seeder
{
    public const SOURCE = 'pending-round3-2026-07-29';

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command?->error('No hay usuario admin. Crear un admin antes de cargar el diccionario.');
            return;
        }

        $service = app(CorrectionService::class);

        // Adjetivos técnicos sin tilde (singular, plural, masc, fem)
        // Extraídos del análisis de 9.3M segmentos de últimos 30 días
        $pendientes = [
            // === Grupo: médicos / clínicos ===
            ['medica',         'médica'],
            ['medico',         'médico'],
            ['medicas',        'médicas'],
            ['medicos',        'médicos'],
            ['clinica',        'clínica'],
            ['clinicas',       'clínicas'],
            ['clinico',        'clínico'],
            ['clinicos',       'clínicos'],
            ['oncologica',     'oncológica'],
            ['oncologico',     'oncológico'],
            ['cardiologica',   'cardiológica'],
            ['neurologica',    'neurológica'],
            ['neurologico',    'neurológico'],
            ['pediatrica',     'pediátrica'],
            ['pediatrico',     'pediátrico'],
            ['psicologica',    'psicológica'],
            ['psicologico',    'psicológico'],
            ['quirurgica',     'quirúrgica'],
            ['diagnosticos',   'diagnósticos'],

            // === Grupo: políticos / sociales ===
            ['politica',       'política'],
            ['politico',       'político'],
            ['politicas',      'políticas'],
            ['politicos',      'políticos'],
            ['publicas',       'públicas'],
            ['publicos',       'públicos'],
            ['democratico',    'democrático'],
            ['democratica',    'democrática'],

            // === Grupo: económicos / estadísticos ===
            ['economica',      'económica'],
            ['economico',      'económico'],
            ['economicas',     'económicas'],
            ['economicos',     'económicos'],
            ['estadistica',    'estadística'],
            ['cientifico',     'científico'],
            ['cientificos',    'científicos'],
            ['cientificamente','científicamente'],

            // === Grupo: técnicos / académicos ===
            ['tecnica',        'técnica'],
            ['academica',      'académica'],
            ['academico',      'académico'],
            ['biologica',      'biológica'],
            ['biologico',      'biológico'],
            ['geografica',     'geográfica'],
            ['geografico',     'geográfico'],
            ['electronica',    'electrónica'],
            ['electronico',    'electrónico'],
            ['electronicas',   'electrónicas'],
            ['electronicos',   'electrónicos'],
            ['telefonica',     'telefónica'],
            ['informatica',    'informática'],
            ['informaticos',   'informáticos'],
            ['energetica',     'energética'],
            ['energeticas',    'energéticas'],
            ['energetico',     'energético'],
            ['hidrica',        'hídrica'],
            ['hidricas',       'hídricas'],
            ['hidrico',        'hídrico'],
            ['hidricos',       'hídricos'],
            ['mecanica',       'mecánica'],
            ['mecanico',       'mecánico'],
            ['mecanicas',      'mecánicas'],
            ['mecanicos',      'mecánicos'],
            ['sistemico',      'sistémico'],
            ['sistemica',      'sistémica'],
            ['sistemicos',     'sistémicos'],
            ['sistematicamente','sistemáticamente'],

            // === Grupo: adjetivos ya aprobados pero en plurales/femeninos no capturados ===
            ['comica',         'cómica'],
            ['comicas',        'cómicas'],
            ['comicos',        'cómicos'],
            ['magica',         'mágica'],
            ['magicas',        'mágicas'],
            ['magicos',        'mágicos'],
            ['tipica',         'típica'],
            ['tipicas',        'típicas'],
            ['tipicos',        'típicos'],
            ['clasica',        'clásica'],
            ['clasicas',       'clásicas'],
            ['clasicos',       'clásicos'],
            ['turistica',      'turística'],
            ['turisticas',     'turísticas'],
            ['turisticos',     'turísticos'],
            ['artistica',      'artística'],
            ['artisticas',     'artísticas'],
            ['artisticos',     'artísticos'],
            ['caracteristicos','característicos'],
            ['fosil',          'fósil'],
            ['fosiles',        'fósiles'],

            // === Grupo: acentos coloquiales ===
            ['tambien',        'también'],

            // === Grupo: frases estructurales EN→ES adicionales ===
            ['across the world',    'por todo el mundo'],
            ['into the world',      'en el mundo'],
            ['through the world',   'por el mundo'],
            ['throughout the world','en todo el mundo'],
            ['within the world',    'dentro del mundo'],
        ];

        $countAdded = 0;
        $countSkipped = 0;
        foreach ($pendientes as [$wrong, $correct]) {
            // Skip si ya está como approved o como pending en otra ronda
            $existingApproved = Correction::approved()
                ->where('wrong_normalized', Correction::normalize($wrong))
                ->exists();
            $existingPending = Correction::pending()
                ->where('wrong_normalized', Correction::normalize($wrong))
                ->exists();

            if ($existingApproved || $existingPending) {
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
        $this->command?->info("  skipped (ya existen): {$countSkipped}");
        $this->command?->info("  total en este source: {$total}");
    }
}