<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Queries de "todos los sitios externos del usuario X" usan user_id
        // pero el UNIQUE(external_site_id, user_id) no cubre búsquedas por user_id solo
        DB::statement('CREATE INDEX idx_external_site_user_user_id ON external_site_user (user_id)');

        // Queries de "todos los grabadores del usuario X" tienen el mismo problema
        DB::statement('CREATE INDEX idx_grabador_usuario_user_id ON grabador_usuario (user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_external_site_user_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_grabador_usuario_user_id');
    }
};
