<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grabador_usuario', function (Blueprint $table) {
            $table->string('ruta_base', 449)->nullable()->after('limite_canales');
        });

        DB::statement("
            UPDATE grabador_usuario gu
            SET ruta_base = sub.ruta_base_derivada
            FROM (
                SELECT DISTINCT ON (grabador_id, usuario_id)
                    grabador_id,
                    usuario_id,
                    regexp_replace(ruta_destino, '/[^/]+$', '') AS ruta_base_derivada
                FROM canales
                WHERE ruta_destino IS NOT NULL
                ORDER BY grabador_id, usuario_id, id
            ) sub
            WHERE gu.grabador_id = sub.grabador_id
              AND gu.user_id = sub.usuario_id
              AND gu.ruta_base IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('grabador_usuario', function (Blueprint $table) {
            $table->dropColumn('ruta_base');
        });
    }
};