<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grabadores', function (Blueprint $table) {
            $table->string('tipo', 20)->default('radio')->after('nombre');
        });

        Schema::table('canales', function (Blueprint $table) {
            $table->foreignId('usuario_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('grabadores', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('canales', function (Blueprint $table) {
            $table->foreignId('usuario_id')->nullable(false)->change();
        });
    }
};
