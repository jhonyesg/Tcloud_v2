<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * mis-avisos-menciones — Fase 1 (motor).
 *
 * 1. segment_keyword_hits: coincidencias COMPARTIDAS entre clientes.
 *    Antes KeywordMatcher creaba una fila por usuario; con clientes que
 *    comparten keyword y store el mismo segmento se escaneaba N veces y
 *    se persistía N veces. El hit existe UNA vez y el reparto por usuario
 *    se deriva relacionalmente (alert_deliveries).
 *
 * 2. user_keyword_storage: alcance keyword→store elegido por el cliente.
 *    Semántica "sin filas = todos sus storages con transcription_access"
 *    (retrocompatible). La frontera dura la pone el admin en user_storages;
 *    este scope nunca puede ampliarla.
 *
 * 3. alert_deliveries: entrega diferida por usuario. El scan NO envía
 *    correo; un scheduler por minuto agrupa los vencidos según la
 *    cadencia elegida (alert_frequency_minutes) con techo emails_quota.
 *
 * La extensión pg_trgm y los índices GIN de transcription_segments ya
 * quedaron cubiertos por la migración 2026_09_05_100100 del change
 * corrections-manual-only-and-context-search (tarea 1.2 de este change).
 *
 * 0 filas vivas que migrar (keyword_matches y user_keyword en cero).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('segment_keyword_hits')) {
            Schema::create('segment_keyword_hits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transcription_id')->constrained('transcriptions')->cascadeOnDelete();
                $table->foreignId('segment_id')->constrained('transcription_segments')->cascadeOnDelete();
                $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
                $table->string('snippet', 500);
                $table->timestamp('matched_at')->useCurrent();

                // Idempotencia + compartir: (transcripción, segmento, keyword) una vez.
                $table->unique(['transcription_id', 'segment_id', 'keyword_id'], 'skh_unique_tsk');
                $table->index(['keyword_id', 'matched_at']);
                $table->index('transcription_id');
            });
        }

        if (!Schema::hasTable('user_keyword_storage')) {
            Schema::create('user_keyword_storage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
                $table->foreignId('storage_provider_id')->constrained('storage_providers')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'keyword_id', 'storage_provider_id'], 'uks_unique');
            });
        }

        if (!Schema::hasColumn('user_alerts_inteligentes', 'alert_frequency_minutes')) {
            Schema::table('user_alerts_inteligentes', function (Blueprint $table) {
                // Escala: 1|5|15|20|30|50|60|240|480|1440. Default 30 min.
                $table->integer('alert_frequency_minutes')->default(30);
            });
        }

        if (!Schema::hasTable('alert_deliveries')) {
            Schema::create('alert_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('hit_id')->constrained('segment_keyword_hits')->cascadeOnDelete();
                $table->timestamp('due_at')->index();
                $table->timestamp('delivered_at')->nullable();
                $table->uuid('batch_id')->nullable();
                $table->timestamp('reposition_for')->nullable(); // reposición tras techo diario
                $table->timestamps();

                $table->index(['user_id', 'delivered_at', 'due_at'], 'ad_user_pending_idx');
                // El fanOut corre una vez por scan, pero UNIQUE(user_id, hit_id)
                // blinda duplicados ante cualquier re-ejecución concurrente.
                $table->unique(['user_id', 'hit_id'], 'ad_unique_user_hit');
            });
        }

        if (!Schema::hasTable('mentions_exports')) {
            Schema::create('mentions_exports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 20)->default('queued'); // queued|processing|ready|failed|cancelled|expired
                $table->jsonb('filters')->nullable();
                $table->unsignedInteger('rows_count')->nullable();
                $table->string('file_path')->nullable();
                $table->text('download_url')->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('emailed_at')->nullable(); // envío manual del link
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        Log::info('migrations.mis_avisos_menciones_phase1_tables_created');
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions_exports');
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('user_keyword_storage');
        Schema::dropIfExists('segment_keyword_hits');
        Schema::table('user_alerts_inteligentes', function (Blueprint $table) {
            if (Schema::hasColumn('user_alerts_inteligentes', 'alert_frequency_minutes')) {
                $table->dropColumn('alert_frequency_minutes');
            }
        });
    }
};