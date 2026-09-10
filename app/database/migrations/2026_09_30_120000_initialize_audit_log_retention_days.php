<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * avisos-scan-coverage-completion: inicializa SystemSetting
 * 'audit_log_retention_days' = 90 (default) si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('system_settings')
            ->where('key', 'audit_log_retention_days')
            ->exists();

        if (!$exists) {
            DB::table('system_settings')->insert([
                'key' => 'audit_log_retention_days',
                'value' => '90',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'audit_log_retention_days')->delete();
    }
};
