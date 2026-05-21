<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Cubre la query de listado: WHERE storage_provider_id = X AND parent_id = Y ORDER BY is_folder DESC, created_at DESC
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_files_listing ON files (storage_provider_id, parent_id, is_folder DESC, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_listing');
    }
};
