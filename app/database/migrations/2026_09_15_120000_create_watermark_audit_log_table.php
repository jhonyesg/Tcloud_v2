<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avisos-scan-coverage-audit: log append-only de acciones sobre watermarks.
 *
 * Resuelve el gap de compliance detectado en auditoría:
 * rewindWatermark y runFullScan no registraban actor ni timestamp.
 *
 * Schema:
 *  - actor_user_id (NULL para acciones del sistema: hooks, reconciler)
 *  - action (enum: rewind_pair | full_scan | hook_auto | reconcile)
 *  - keyword_id, storage_id (nullable; full_scan cubre cualquier keyword×storage)
 *  - before_value, after_value (scanned_until antes/después)
 *  - metadata JSONB (request payload, motivo del hook, IP, UA)
 *  - 3 índices para queries típicas de auditoría
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('watermark_audit_log')) {
            return;
        }

        Schema::create('watermark_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->foreignId('storage_id')->nullable()->constrained('storage_providers')->nullOnDelete();
            $table->timestamp('before_value')->nullable();
            $table->timestamp('after_value')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at');

            $table->index(['keyword_id', 'storage_id', 'created_at'], 'wal_ks_time_idx');
            $table->index(['actor_user_id', 'created_at'], 'wal_actor_time_idx');
            $table->index(['action', 'created_at'], 'wal_action_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_audit_log');
    }
};
