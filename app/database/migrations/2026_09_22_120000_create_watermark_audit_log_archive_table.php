<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * avisos-scan-coverage-observability-and-ux: tabla paralela para archivar
 * filas antiguas de watermark_audit_log.
 *
 * Misma estructura + columna `archived_at` (timestamp(0)) para distinguir
 * filas en el log activo vs histórico. 3 índices para responder a las mismas
 * consultas que watermark_audit_log (actor, action, par k×s).
 *
 * La operación de archivado NO está aquí (es el comando
 * `avisos:archive-audit-log` que mueve chunks de 1000 filas). Esta migración
 * solo crea la estructura.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('watermark_audit_log_archive')) {
            return;
        }

        Schema::create('watermark_audit_log_archive', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->foreignId('storage_id')->nullable()->constrained('storage_providers')->nullOnDelete();
            $table->timestamp('before_value')->nullable();
            $table->timestamp('after_value')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at');
            $table->timestamp('archived_at');

            $table->index(['actor_user_id', 'created_at'], 'wal_archive_actor_time_idx');
            $table->index(['action', 'created_at'], 'wal_archive_action_time_idx');
            $table->index(['keyword_id', 'storage_id', 'created_at'], 'wal_archive_ks_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_audit_log_archive');
    }
};
