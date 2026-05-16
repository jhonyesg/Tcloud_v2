<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Queries de trabajos pendientes/fallidos filtran por status frecuentemente
        DB::statement("CREATE INDEX idx_media_edit_jobs_status ON media_edit_jobs (status)");

        // Índice parcial más eficiente para monitorear jobs activos
        DB::statement("CREATE INDEX idx_media_edit_jobs_processing
            ON media_edit_jobs (user_id, created_at)
            WHERE status = 'processing'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_media_edit_jobs_status');
        DB::statement('DROP INDEX IF EXISTS idx_media_edit_jobs_processing');
    }
};
