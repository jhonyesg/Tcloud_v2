<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->string('detalle')->nullable()->after('ruta_destino');
            $table->string('duracion_grabacion', 20)->default('00:21:00')->after('detalle');
            $table->string('ffmpeg_args_pre', 100)->nullable()->after('duracion_grabacion');
            $table->string('ffmpeg_args_post', 100)->nullable()->after('ffmpeg_args_pre');
            $table->string('formato_salida', 10)->default('.mp3')->after('ffmpeg_args_post');
        });
    }

    public function down(): void
    {
        Schema::table('canales', function (Blueprint $table) {
            $table->dropColumn([
                'detalle', 'duracion_grabacion',
                'ffmpeg_args_pre', 'ffmpeg_args_post', 'formato_salida',
            ]);
        });
    }
};
