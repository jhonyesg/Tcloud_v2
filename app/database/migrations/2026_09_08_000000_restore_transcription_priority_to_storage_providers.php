<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('storage_providers', 'transcription_priority')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->integer('transcription_priority')->default(0)->after('transcription_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('storage_providers', 'transcription_priority')) {
            Schema::table('storage_providers', function (Blueprint $table) {
                $table->dropColumn('transcription_priority');
            });
        }
    }
};
