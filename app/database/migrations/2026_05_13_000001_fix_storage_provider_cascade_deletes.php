<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // share_access_log → shares (needed so shares can be cascade-deleted)
        DB::statement('ALTER TABLE share_access_log DROP CONSTRAINT IF EXISTS share_access_log_share_id_fkey');
        DB::statement('ALTER TABLE share_access_log ADD CONSTRAINT share_access_log_share_id_fkey
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE');

        // shares → files (needed so files can be cascade-deleted)
        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_file_id_fkey');
        DB::statement('ALTER TABLE shares ADD CONSTRAINT shares_file_id_fkey
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE');

        // files self-reference parent_id (needed so folders cascade to children)
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_parent_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_parent_id_fkey
            FOREIGN KEY (parent_id) REFERENCES files(id) ON DELETE CASCADE');

        // files → storage_providers
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_storage_provider_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_storage_provider_id_fkey
            FOREIGN KEY (storage_provider_id) REFERENCES storage_providers(id) ON DELETE CASCADE');

        // user_storages → storage_providers
        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_storage_provider_id_fkey');
        DB::statement('ALTER TABLE user_storages ADD CONSTRAINT user_storages_storage_provider_id_fkey
            FOREIGN KEY (storage_provider_id) REFERENCES storage_providers(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE share_access_log DROP CONSTRAINT IF EXISTS share_access_log_share_id_fkey');
        DB::statement('ALTER TABLE share_access_log ADD CONSTRAINT share_access_log_share_id_fkey
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_file_id_fkey');
        DB::statement('ALTER TABLE shares ADD CONSTRAINT shares_file_id_fkey
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_parent_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_parent_id_fkey
            FOREIGN KEY (parent_id) REFERENCES files(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_storage_provider_id_fkey');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_storage_provider_id_fkey
            FOREIGN KEY (storage_provider_id) REFERENCES storage_providers(id) ON DELETE NO ACTION');

        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_storage_provider_id_fkey');
        DB::statement('ALTER TABLE user_storages ADD CONSTRAINT user_storages_storage_provider_id_fkey
            FOREIGN KEY (storage_provider_id) REFERENCES storage_providers(id) ON DELETE NO ACTION');
    }
};
