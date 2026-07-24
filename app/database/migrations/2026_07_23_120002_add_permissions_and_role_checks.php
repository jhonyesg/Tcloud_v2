<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'user'))");
        DB::statement("ALTER TABLE user_storages ADD CONSTRAINT user_storages_permissions_check CHECK (permissions IN ('read', 'write', 'upload', 'full'))");
        DB::statement("ALTER TABLE shares ADD CONSTRAINT shares_permissions_check CHECK (permissions IN ('read', 'write', 'upload', 'full'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_permissions_check');
        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_permissions_check');
    }
};
