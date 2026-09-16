<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * avisos-keyword-storage-watermark: retira el cursor global legacy.
 *
 * SystemSetting('avisos_scan_cursor') era un cursor monolítico de "última transcripción
 * procesada en el modo drenaje" sin granularidad por keyword ni storage. Es reemplazado
 * por la cobertura per-par en `keyword_scan_watermarks`.
 *
 * Tras esta migración, el código de AvisosScanService puede dejar de leer/escribir
 * dicho setting (tarea 3.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'avisos_scan_cursor')
            ->delete();
    }

    public function down(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'avisos_scan_cursor',
            'value' => '',
            'updated_at' => now(),
        ]);
    }
};
