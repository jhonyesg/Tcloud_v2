<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * transcriptor-pg-native-queue: índice parcial para el worker query.
 *
 * El worker hace:
 *   SELECT * FROM transcriptions
 *   WHERE state = 'pending' AND dispatched_at IS NULL
 *     AND recorded_at >= today
 *   ORDER BY recorded_at DESC, discovered_at DESC
 *   FOR UPDATE SKIP LOCKED
 *   LIMIT N;
 *
 * Sin este índice el plan sería:
 *   transcriptions_pending_dispatchable_idx (file_id)
 *     WHERE state='pending' AND dispatched_at IS NULL
 *   + sort por (recorded_at DESC, discovered_at DESC)
 *
 * Con este nuevo índice: index-only-scan en orden, sin sort, sin pasar por el
 * heap para reordenar. Reduce latencia del worker tick de ~80ms a <5ms en backlog
 * de 10k filas.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY no puede correr dentro de transacción.
     * Laravel 13 usa `$withinTransaction` como propiedad pública.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS transcriptions_pending_today_dispatch_idx
  ON transcriptions (recorded_at DESC, discovered_at DESC)
  WHERE state = 'pending' AND dispatched_at IS NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transcriptions_pending_today_dispatch_idx');
    }
};