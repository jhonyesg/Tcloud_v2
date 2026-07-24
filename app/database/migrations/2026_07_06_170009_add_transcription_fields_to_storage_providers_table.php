<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->boolean('transcription_enabled')->default(false);
            $table->integer('transcription_priority')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->dropColumn(['transcription_enabled', 'transcription_priority']);
        });
    }
};