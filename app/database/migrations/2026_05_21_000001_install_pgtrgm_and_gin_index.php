<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_files_name_gin');
        DB::statement('CREATE INDEX idx_files_name_gin ON files USING GIN (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_name_gin');
    }
};
