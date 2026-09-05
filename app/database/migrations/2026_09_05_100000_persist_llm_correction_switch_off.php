<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * corrections-manual-only-and-context-search (2026-09-05).
 *
 * Persiste el master switch del suggester LLM en OFF. Estado previo: la fila
 * `llm-correction.enabled` NO existía en system_settings, así que
 * LlmCorrectionSettings::get() caía a config('llm-correction.enabled') →
 * env('LLM_CORRECTION_ENABLED', true), y el .env de producción no define
 * variables LLM: el default `true` ganaba y contradecía la política
 * defaults-off ratificada el 2026-08-25 (incidente de hemorragia de tokens).
 *
 * Idempotente: solo inserta si la fila no existe (el admin puede haber
 * elegido un valor consciente y ese se respeta).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('system_settings')->where('key', 'llm-correction.enabled')->exists();

        if (!$exists) {
            DB::table('system_settings')->insert([
                'key' => 'llm-correction.enabled',
                'value' => '0',
                'updated_at' => now(),
            ]);

            Log::info('migrations.llm_correction_switch_persisted', [
                'key' => 'llm-correction.enabled',
                'value' => '0',
                'reason' => 'manual-only policy (corrections-manual-only-and-context-search)',
            ]);
        }
    }

    public function down(): void
    {
        // Reversible sin efecto adverso: al borrar la fila, la resolución vuelve
        // a config() → env default true. Es exactamente el estado previo.
        DB::table('system_settings')->where('key', 'llm-correction.enabled')->delete();
    }
};