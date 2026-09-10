<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avisos-keyword-storage-watermark: cobertura de escaneo por par (keyword, storage).
 *
 * Reemplaza el cursor global SystemSetting('avisos_scan_cursor'). Cada par
 * (keyword_id, storage_provider_id) rastrea su propio scanned_until monotónico,
 * permitiendo:
 *  - catch-up automático de keywords nuevas sin re-escanear las ya cubiertas
 *  - full scan reanudable con progreso por par
 *  - avance atómico race-safe vía UPSERT GREATEST
 *
 * Cardinalidad estimada: keywords activas × storages con acceso (≈100-200 filas).
 * PK compuesta garantiza UPSERT idempotente sin unicidad adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('keyword_scan_watermarks')) {
            return;
        }

        Schema::create('keyword_scan_watermarks', function (Blueprint $table) {
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->foreignId('storage_provider_id')->constrained('storage_providers')->cascadeOnDelete();
            $table->timestamp('scanned_until')->nullable();
            $table->foreignId('last_scan_run_id')->nullable()->constrained('avisos_scan_runs')->nullOnDelete();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_hit_at')->nullable();
            $table->unsignedBigInteger('candidates_total')->default(0);
            $table->unsignedBigInteger('hits_total')->default(0);
            $table->timestamps();

            $table->primary(['keyword_id', 'storage_provider_id']);
            $table->index(['storage_provider_id', 'scanned_until'], 'ksw_storage_scanned_idx');
            $table->index(['scanned_until'], 'ksw_scanned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_scan_watermarks');
    }
};
