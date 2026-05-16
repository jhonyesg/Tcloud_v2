<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Los controladores ya validan in:read,write,upload,full — la BD lo refuerza
        DB::statement("ALTER TABLE user_storages ADD CONSTRAINT user_storages_permissions_check
            CHECK (permissions IN ('read', 'write', 'upload', 'full'))");

        DB::statement("ALTER TABLE shares ADD CONSTRAINT shares_permissions_check
            CHECK (permissions IN ('read', 'write', 'upload', 'full'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_storages DROP CONSTRAINT IF EXISTS user_storages_permissions_check');
        DB::statement('ALTER TABLE shares DROP CONSTRAINT IF EXISTS shares_permissions_check');
    }
};
