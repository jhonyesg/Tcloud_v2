<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('storage_providers', 'folder_layout')) {
                $table->string('folder_layout', 20)
                    ->default('flat')
                    ->after('transcription_enabled');
            }
            if (!Schema::hasColumn('storage_providers', 'allow_parent_overlap')) {
                $table->boolean('allow_parent_overlap')
                    ->default(false)
                    ->after('folder_layout');
            }
        });

        DB::statement("ALTER TABLE storage_providers
                       DROP CONSTRAINT IF EXISTS storage_providers_folder_layout_check");
        DB::statement("ALTER TABLE storage_providers
                       ADD CONSTRAINT storage_providers_folder_layout_check
                       CHECK (folder_layout IN ('flat', 'grouped_by_subfolder'))");

        DB::table('storage_providers')
            ->whereIn('id', [47, 49])
            ->update(['folder_layout' => 'grouped_by_subfolder']);

        if (Schema::hasColumn('storage_providers', 'transcription_priority')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->dropColumn('transcription_priority');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('storage_providers', 'transcription_priority')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->integer('transcription_priority')->default(0)->after('folder_layout');
            });
        }
        DB::table('storage_providers')->update(['transcription_priority' => 0]);

        DB::statement("ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_folder_layout_check");

        if (Schema::hasColumn('storage_providers', 'allow_parent_overlap')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->dropColumn('allow_parent_overlap');
            });
        }
        if (Schema::hasColumn('storage_providers', 'folder_layout')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->dropColumn('folder_layout');
            });
        }
    }
};