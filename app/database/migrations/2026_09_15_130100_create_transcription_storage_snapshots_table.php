<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transcriptor-pg-native-queue: tabla de snapshots por storage cada 15 minutos.
 *
 * Alimenta la tarjeta del tab Storages de /ia/api-transcriptor con el último
 * agregado por storage, incluyendo métricas PG locales (pending/inflight/sent/error)
 * y la lectura de /api/metrics/overview del upstream (remote_queue_queued) cacheada
 * para no pegar HTTP en cada render.
 *
 * Retención: 7 días (transcriptor:prune-storage-snapshots --days=7 corre diario).
 *
 * Aprox 96 snapshots/día × ~70 storages habilitados = ~470k filas/semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcription_storage_snapshots', function (Blueprint $table) {
            $table->bigInteger('storage_provider_id');
            $table->timestampTz('captured_at');
            $table->integer('pending_count')->default(0);
            $table->integer('inflight_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->integer('oldest_pending_age_seconds')->nullable();
            $table->integer('remote_queue_queued')->nullable();

            $table->primary(['storage_provider_id', 'captured_at']);
        });

        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
CREATE INDEX transcription_storage_snapshots_recent_idx
  ON transcription_storage_snapshots (storage_provider_id, captured_at DESC)
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_storage_snapshots');
    }
};