<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega columna risk_level a corrections para el sistema de protección
     * de contexto/tono (changes/2026-08-02-corrections-dictionary-atomicity).
     *
     * Valores:
     *   - 'low'    : segura, se aplica automáticamente (default).
     *   - 'medium' : requiere revisión, se aplica pero conviene auditar.
     *   - 'high'   : se omite de applyToText() automático; solo con --include-high-risk.
     *
     * El backfill se hace con `php artisan corrections:context-audit --apply`
     * después de la migración (corre en el mismo deploy).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('corrections', 'risk_level')) {
            Schema::table('corrections', function (Blueprint $table) {
                $table->string('risk_level', 10)
                    ->default('low')
                    ->after('source_segment_id');
                $table->index('risk_level', 'corrections_risk_level_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('corrections', 'risk_level')) {
            Schema::table('corrections', function (Blueprint $table) {
                $table->dropIndex('corrections_risk_level_index');
                $table->dropColumn('risk_level');
            });
        }
    }
};
