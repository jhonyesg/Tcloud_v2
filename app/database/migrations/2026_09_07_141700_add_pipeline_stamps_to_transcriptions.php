<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * optimize-transcriptor-dispatch-throughput: cuatro marcas nuevas en
 * `transcriptions` para instrumentar el pipeline de extremo a extremo.
 *
 * - `discovered_at`:        cuando el scanner vio el archivo en disco
 * - `dispatched_at`:        cuando el tick encolo el job a Redis
 * - `submission_committed_at`: cuando la API externa respondio con job_id
 * - `regulator_skip_reason`: porque el ultimo tick freno el despacho
 *
 * Todas nullable sin default para no falsear el backfill que se corre
 * aparte (las filas pre-existentes quedan con NULL, no con un timestamp
 * inventado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('transcriptions', 'discovered_at')) {
                $table->timestamp('discovered_at')->nullable()->after('started_at');
            }
            if (!Schema::hasColumn('transcriptions', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('discovered_at');
            }
            if (!Schema::hasColumn('transcriptions', 'submission_committed_at')) {
                $table->timestamp('submission_committed_at')->nullable()->after('dispatched_at');
            }
            if (!Schema::hasColumn('transcriptions', 'regulator_skip_reason')) {
                $table->string('regulator_skip_reason', 96)->nullable()->after('submission_committed_at');
            }
        });

        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS transcriptions_pending_dispatchable_idx
  ON transcriptions (file_id)
  WHERE state = 'pending' AND dispatched_at IS NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transcriptions_pending_dispatchable_idx');

        Schema::table('transcriptions', function (Blueprint $table) {
            if (Schema::hasColumn('transcriptions', 'regulator_skip_reason')) {
                $table->dropColumn('regulator_skip_reason');
            }
            if (Schema::hasColumn('transcriptions', 'submission_committed_at')) {
                $table->dropColumn('submission_committed_at');
            }
            if (Schema::hasColumn('transcriptions', 'dispatched_at')) {
                $table->dropColumn('dispatched_at');
            }
            if (Schema::hasColumn('transcriptions', 'discovered_at')) {
                $table->dropColumn('discovered_at');
            }
        });
    }
};
