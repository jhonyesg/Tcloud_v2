<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // jsonb es indexable y más eficiente que json en PostgreSQL
        // El cast de Laravel ('array') funciona igual con ambos tipos
        DB::statement('ALTER TABLE media_edit_jobs ALTER COLUMN segments_json TYPE jsonb USING segments_json::jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media_edit_jobs ALTER COLUMN segments_json TYPE json USING segments_json::json');
    }
};
