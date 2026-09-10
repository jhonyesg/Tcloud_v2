<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * avisos-scan-coverage-observability-and-ux: inicializa system_settings.coverage_cache_epoch = 0
 * si no existe. Usado por CacheEpoch service para invalidar la cache key de
 * coveragePaginated(). El contador se incrementa en cada mutación sobre
 * keyword_scan_watermarks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('system_settings')
            ->where('key', 'coverage_cache_epoch')
            ->exists();

        if (!$exists) {
            DB::table('system_settings')->insert([
                'key' => 'coverage_cache_epoch',
                'value' => '0',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'coverage_cache_epoch')->delete();
    }
};
