<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // canales.grabador_id: original migration 2026_05_09_190002 specified onDelete('cascade')
        DB::statement('ALTER TABLE canales DROP CONSTRAINT IF EXISTS canales_grabador_id_foreign');
        DB::statement('ALTER TABLE canales ADD CONSTRAINT canales_grabador_id_foreign
            FOREIGN KEY (grabador_id) REFERENCES grabadores(id) ON DELETE CASCADE');

        // grabador_usuario.grabador_id: original migration 2026_05_09_190001 specified onDelete('cascade')
        DB::statement('ALTER TABLE grabador_usuario DROP CONSTRAINT IF EXISTS grabador_usuario_grabador_id_foreign');
        DB::statement('ALTER TABLE grabador_usuario ADD CONSTRAINT grabador_usuario_grabador_id_foreign
            FOREIGN KEY (grabador_id) REFERENCES grabadores(id) ON DELETE CASCADE');

        // grabador_usuario.user_id: original migration 2026_05_09_190001 specified onDelete('cascade')
        DB::statement('ALTER TABLE grabador_usuario DROP CONSTRAINT IF EXISTS grabador_usuario_user_id_foreign');
        DB::statement('ALTER TABLE grabador_usuario ADD CONSTRAINT grabador_usuario_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE grabador_usuario DROP CONSTRAINT IF EXISTS grabador_usuario_user_id_foreign');
        DB::statement('ALTER TABLE grabador_usuario ADD CONSTRAINT grabador_usuario_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE grabador_usuario DROP CONSTRAINT IF EXISTS grabador_usuario_grabador_id_foreign');
        DB::statement('ALTER TABLE grabador_usuario ADD CONSTRAINT grabador_usuario_grabador_id_foreign
            FOREIGN KEY (grabador_id) REFERENCES grabadores(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE canales DROP CONSTRAINT IF EXISTS canales_grabador_id_foreign');
        DB::statement('ALTER TABLE canales ADD CONSTRAINT canales_grabador_id_foreign
            FOREIGN KEY (grabador_id) REFERENCES grabadores(id) ON DELETE NO ACTION');
    }
};
