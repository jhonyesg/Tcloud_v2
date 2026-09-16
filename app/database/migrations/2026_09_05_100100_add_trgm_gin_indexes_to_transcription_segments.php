<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * corrections-manual-only-and-context-search (2026-09-05).
 *
 * Índices GIN pg_trgm sobre transcription_segments (text, text_raw).
 *
 * La tabla tiene ~49,8 M filas (13 GB). CorrectionContextFinder::search()
 * corre 2× ILIKE '%…%' (text y text_raw) y TranscriptionReviewService (modo
 * sensibles) usa position() sobre text_raw: sin índice, todo era seq scan de
 * 13 GB y la búsqueda de contexto siempre terminaba en statement_timeout.
 *
 * Dos índices separados (no multicolumna): la búsqueda matchea por cualquiera
 * de las dos columnas, y un GIN multicolumna no serviría ambas rutas.
 *
 * ⚠️ La creación bloquea INSERT/UPDATE/DELETE sobre la tabla durante su
 * construcción (puede tardar decenas de minutos sobre 13 GB). Correr en
 * ventana de madrugada. Alternativa sin bloqueo (manual, posterior):
 *
 *   SET maintenance_work_mem = '2GB';
 *   CREATE INDEX CONCURRENTLY transcription_segments_text_trgm_idx
 *     ON transcription_segments USING gin (text gin_trgm_ops);
 *   CREATE INDEX CONCURRENTLY transcription_segments_text_raw_trgm_idx
 *     ON transcription_segments USING gin (text_raw gin_trgm_ops);
 *
 * (CONCURRENTLY no puede correr dentro de una migración transaccional.)
 * El SET LOCAL de maintenance_work_mem acelera la construcción en la
 * migración estándar; PostgreSQL lo aplica por sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La extensión pg_trgm ya está instalada en producción; IF NOT EXISTS
        // hace el change re-ejecutable contra entornos frescos.
        DB::statement("CREATE EXTENSION IF NOT EXISTS pg_trgm");

        DB::statement("SET maintenance_work_mem = '2GB'");

        DB::statement("
            CREATE INDEX IF NOT EXISTS transcription_segments_text_trgm_idx
            ON transcription_segments USING gin (text gin_trgm_ops)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS transcription_segments_text_raw_trgm_idx
            ON transcription_segments USING gin (text_raw gin_trgm_ops)
        ");

        Log::info('migrations.trgm_gin_indexes_created', [
            'table' => 'transcription_segments',
            'indexes' => [
                'transcription_segments_text_trgm_idx',
                'transcription_segments_text_raw_trgm_idx',
            ],
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transcription_segments_text_raw_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS transcription_segments_text_trgm_idx');
    }
};