<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Correo module: preserve config/templates when user is deleted
        DB::statement('ALTER TABLE correo_config DROP CONSTRAINT IF EXISTS correo_config_updated_by_fkey');
        DB::statement('ALTER TABLE correo_config ADD CONSTRAINT correo_config_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE correo_plantillas DROP CONSTRAINT IF EXISTS correo_plantillas_created_by_fkey');
        DB::statement('ALTER TABLE correo_plantillas ADD CONSTRAINT correo_plantillas_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');

        // Canales: preserve channel record, just remove user assignment
        DB::statement('ALTER TABLE canales DROP CONSTRAINT IF EXISTS canales_usuario_id_foreign');
        DB::statement('ALTER TABLE canales ADD CONSTRAINT canales_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE SET NULL');

        // Core tables: cascade delete with user
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_owner_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_created_by_fkey');
        DB::statement('ALTER TABLE shares ADD CONSTRAINT shares_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_user_id_fkey');
        DB::statement('ALTER TABLE user_storages ADD CONSTRAINT user_storages_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE media_edit_jobs DROP CONSTRAINT IF EXISTS media_edit_jobs_user_id_foreign');
        DB::statement('ALTER TABLE media_edit_jobs ADD CONSTRAINT media_edit_jobs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE correo_config DROP CONSTRAINT IF EXISTS correo_config_updated_by_fkey');
        DB::statement('ALTER TABLE correo_config ADD CONSTRAINT correo_config_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE correo_plantillas DROP CONSTRAINT IF EXISTS correo_plantillas_created_by_fkey');
        DB::statement('ALTER TABLE correo_plantillas ADD CONSTRAINT correo_plantillas_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE canales DROP CONSTRAINT IF EXISTS canales_usuario_id_foreign');
        DB::statement('ALTER TABLE canales ADD CONSTRAINT canales_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES users(id)');

        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_owner_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES users(id)');

        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_created_by_fkey');
        DB::statement('ALTER TABLE shares ADD CONSTRAINT shares_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_user_id_fkey');
        DB::statement('ALTER TABLE user_storages ADD CONSTRAINT user_storages_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id)');

        DB::statement('ALTER TABLE media_edit_jobs DROP CONSTRAINT IF EXISTS media_edit_jobs_user_id_foreign');
        DB::statement('ALTER TABLE media_edit_jobs ADD CONSTRAINT media_edit_jobs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id)');
    }
};
