<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->string('ruta_destino', 500)->nullable()->after('link_origen');
        });
    }

    public function down(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->dropColumn('ruta_destino');
        });
    }
};
