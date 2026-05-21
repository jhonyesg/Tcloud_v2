<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop redundant: shares_token_key UNIQUE already covers token lookups
        DB::statement('DROP INDEX IF EXISTS idx_shares_token');

        // Drop 167MB unused index: 0 scans recorded, no app-level dependency
        DB::statement('DROP INDEX IF EXISTS files_path_storage_provider_id_unique');

        // Add missing indexes for common query patterns
        DB::statement('CREATE INDEX IF NOT EXISTS idx_shares_created_by ON shares (created_by)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_correo_log_sent_at ON correo_log (sent_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_media_edit_jobs_user_status_date ON media_edit_jobs (user_id, status, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_canales_grabador_usuario ON canales (grabador_id, usuario_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_canales_grabador_usuario');
        DB::statement('DROP INDEX IF EXISTS idx_media_edit_jobs_user_status_date');
        DB::statement('DROP INDEX IF EXISTS idx_correo_log_sent_at');
        DB::statement('DROP INDEX IF EXISTS idx_shares_created_by');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS files_path_storage_provider_id_unique ON files (path, storage_provider_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_shares_token ON shares (token)');
    }
};
