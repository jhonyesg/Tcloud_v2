<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Anular referencias a archivos que ya no existen antes de agregar el FK
        DB::statement('
            UPDATE media_edit_jobs
            SET source_file_id = NULL
            WHERE source_file_id IS NOT NULL
              AND source_file_id NOT IN (SELECT id FROM files)
        ');

        // FK con ON DELETE SET NULL: si el archivo se borra, el ID queda null
        // pero source_file_name preserva el nombre histórico del job
        DB::statement('
            ALTER TABLE media_edit_jobs
            ADD CONSTRAINT media_edit_jobs_source_file_id_fkey
            FOREIGN KEY (source_file_id) REFERENCES files(id) ON DELETE SET NULL
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media_edit_jobs DROP CONSTRAINT IF EXISTS media_edit_jobs_source_file_id_fkey');
    }
};
