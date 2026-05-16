<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('admin', 'user'))");

        DB::statement("ALTER TABLE grabadores ADD CONSTRAINT grabadores_tipo_check
            CHECK (tipo IN ('radio', 'tv'))");

        DB::statement("ALTER TABLE canales ADD CONSTRAINT canales_formato_salida_check
            CHECK (formato_salida IN ('.mp3', '.mp4'))");

        DB::statement("ALTER TABLE media_edit_jobs ADD CONSTRAINT media_edit_jobs_status_check
            CHECK (status IN ('processing', 'done', 'failed'))");

        DB::statement("ALTER TABLE external_sites ADD CONSTRAINT external_sites_color_check
            CHECK (color IN ('blue', 'green', 'red', 'purple', 'amber', 'cyan', 'rose', 'slate'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE grabadores DROP CONSTRAINT IF EXISTS grabadores_tipo_check');
        DB::statement('ALTER TABLE canales DROP CONSTRAINT IF EXISTS canales_formato_salida_check');
        DB::statement('ALTER TABLE media_edit_jobs DROP CONSTRAINT IF EXISTS media_edit_jobs_status_check');
        DB::statement('ALTER TABLE external_sites DROP CONSTRAINT IF EXISTS external_sites_color_check');
    }
};
