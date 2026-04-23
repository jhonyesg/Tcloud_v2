<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function ($table) {
            $table->index('parent_id');
            $table->index('storage_provider_id');
            $table->index('owner_id');
        });

        DB::statement('CREATE INDEX idx_files_personal ON files(owner_id, is_personal) WHERE is_personal = true');

        Schema::table('shares', function ($table) {
            $table->index('token');
            $table->index('file_id');
        });

        Schema::table('user_storages', function ($table) {
            $table->index('user_id');
            $table->index('storage_provider_id');
        });

        Schema::table('share_access_log', function ($table) {
            $table->index('share_id');
            $table->index('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function ($table) {
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['storage_provider_id']);
            $table->dropIndex(['owner_id']);
        });

        DB::statement('DROP INDEX IF EXISTS idx_files_personal');

        Schema::table('shares', function ($table) {
            $table->dropIndex(['token']);
            $table->dropIndex(['file_id']);
        });

        Schema::table('user_storages', function ($table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['storage_provider_id']);
        });

        Schema::table('share_access_log', function ($table) {
            $table->dropIndex(['share_id']);
            $table->dropIndex(['accessed_at']);
        });
    }
};
