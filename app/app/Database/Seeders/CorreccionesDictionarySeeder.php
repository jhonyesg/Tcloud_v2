<?php

namespace App\Database\Seeders;

use App\Models\User;
use App\Services\Ia\CorrectionService;
use Illuminate\Database\Seeder;

/**
 * Puebla el diccionario de correcciones con realizaciones detectadas en
 * el corpus de producción del 2026-07-24. Idempotente: usa
 * `upsertApproved()` que actualiza el `correct_text` si la fila
 * approved existe.
 *
 * Ejecutar manualmente:
 *   php artisan db:seed --class=CorreccionesDictionarySeeder
 */
class CorreccionesDictionarySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command?->error('No hay usuario admin. Crear un admin antes de cargar el diccionario.');
            return;
        }

        $service = app(CorrectionService::class);

        $seeds = [
            [
                'wrong' => 'Active to',
                'correct' => 'Activa tu',
                'note' => 'Cuña radial "Bogotá Modo Metro" — variant Mayúscula',
            ],
            [
                'wrong' => 'active to',
                'correct' => 'Activa tu',
                'note' => 'Cuña radial "Bogotá Modo Metro" — variant minúscula',
            ],
            [
                'wrong' => 'valor the time',
                'correct' => 'valorar el tiempo',
                'note' => 'Spanglish recurrente en cuña "Bogotá Modo Metro"',
            ],
            [
                'wrong' => 'orgular',
                'correct' => 'orgullo',
                'note' => 'ASR misrecognition — variante ortográfica',
            ],
            [
                'wrong' => 'with orgasm',
                'correct' => 'with orgullo',
                'note' => 'ASR misrecognition — variante humorística',
            ],
            [
                'wrong' => 'applicate vacunes',
                'correct' => 'aplicarse vacunas',
                'note' => 'Spanglish en pauta de salud',
            ],
        ];

        foreach ($seeds as $seed) {
            $correction = $service->upsertApproved($seed['wrong'], $seed['correct'], $admin);
            $this->command?->info(sprintf(
                'Corrección #%d: "%s" → "%s" (%s)',
                $correction->id,
                $seed['wrong'],
                $seed['correct'],
                $seed['note']
            ));
        }

        $count = \App\Models\Correction::where('status', \App\Models\Correction::STATUS_APPROVED)->count();
        $this->command?->info("Total correcciones aprobadas: {$count}");
    }
}
