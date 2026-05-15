<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('max_sessions')->default(6)->after('media_editor_clip_limit');
            $table->integer('session_lifetime_minutes')->nullable()->after('max_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['max_sessions', 'session_lifetime_minutes']);
        });
    }
};
