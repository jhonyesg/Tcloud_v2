<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('pending_deletion_at')->nullable()->after('file_modified_at');
        });

        \Illuminate\Support\Facades\DB::statement(
            'CREATE INDEX files_pending_deletion_idx ON files (storage_provider_id, pending_deletion_at)'
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS files_pending_deletion_idx');

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('pending_deletion_at');
        });
    }
};
