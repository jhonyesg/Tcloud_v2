<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop global unique on path, replace with composite unique (path, storage_provider_id)
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_path_unique');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_path_storage_provider_id_unique');
        DB::statement('CREATE UNIQUE INDEX files_path_storage_provider_id_unique ON files (path, storage_provider_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS files_path_storage_provider_id_unique');
        DB::statement('CREATE UNIQUE INDEX files_path_unique ON files (path)');
    }
};
