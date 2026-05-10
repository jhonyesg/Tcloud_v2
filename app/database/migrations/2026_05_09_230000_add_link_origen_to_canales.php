<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->string('link_origen')->nullable()->after('api_canal_id');
        });
    }

    public function down(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->dropColumn('link_origen');
        });
    }
};
