<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avisos-scan-configuration: corridas del escaneo de menciones.
 *
 * Un registro por corrida REAL (ejecutada). Los ticks que deciden "no toca"
 * (fuera de intervalo o escaneo automático desactivado) solo registran Log,
 * no filas — evita inundar la tabla cada 5 minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avisos_scan_runs')) {
            return;
        }

        Schema::create('avisos_scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('origin', 20);                 // cron | manual
            $table->string('status', 20);                 // success | failed
            $table->jsonb('params')->default('{}');       // filtros de la corrida
            $table->unsignedInteger('transcriptions_scanned')->default(0);
            $table->unsignedBigInteger('hits_new')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['origin', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_scan_runs');
    }
};