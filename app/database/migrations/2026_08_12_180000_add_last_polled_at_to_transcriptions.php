<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_polled_at` permite que TranscriptionPollingService::pollAll() reparta
 * los slots de poll_limit en vez de reconsultar siempre la misma cabecera.
 *
 * El orden anterior (`id DESC LIMIT 140`) era una ventana fija: una fila que
 * nunca resuelve se queda dentro para siempre y tapa a las de detras. El
 * 2026-08-12 habia 33.571 filas del 1-5 de agosto en ese estado (SRT purgado
 * upstream, GET .../srt -> 500) gastando ~139 de los 140 slots de cada ciclo.
 *
 * Nullable y sin default: en Postgres es solo metadato, no reescribe la tabla
 * (1,3 GB / 188k filas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->timestamp('last_polled_at')->nullable()->after('finished_at');
        });

        // El poll filtra por state y ordena por last_polled_at.
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->index(['state', 'last_polled_at'], 'transcriptions_state_last_polled_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex('transcriptions_state_last_polled_at_idx');
            $table->dropColumn('last_polled_at');
        });
    }
};
