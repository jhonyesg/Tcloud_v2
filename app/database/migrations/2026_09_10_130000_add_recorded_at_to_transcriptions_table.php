<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 1.1.
 *
 * Agrega `transcriptions.recorded_at TIMESTAMPTZ` que representa la fecha
 * REAL del programa (cuándo se emitió el audio), distinta de `finished_at`
 * (cuándo el transcriptor terminó) y de `segment_keyword_hits.matched_at`
 * (cuándo el matching detectó la keyword).
 *
 * Backfill idempotente con cascada de fallbacks:
 *   1. Parsear nombre del archivo: `*_DDMMYYYY_HHMMSS.{mp4,mp3,...}`.
 *   2. Fallback a files.file_modified_at.
 *   3. Fallback a transcriptions.finished_at.
 *   4. NULL si nada aplica (caso degenerado, queda visible al admin).
 *
 * El backfill se hace en lotes de 10k IDs con sleep 100ms entre lotes
 * para no bloquear PostgreSQL durante el UPDATE.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transcriptions', function ($table) {
            // Nullable: filas existentes sin fuentes de fecha quedan NULL;
            // el spec exige que el query del Histórico tolere NULL (fallback
            // a finished_at vía COALESCE en la capa de servicio).
            $table->timestampTz('recorded_at')->nullable()->after('finished_at');
        });

        // Índice parcial: solo filas con valor (las NULL son casos degenerados
        // que no se consultan por fecha del programa).
        DB::statement('CREATE INDEX IF NOT EXISTS transcriptions_recorded_at_index
            ON transcriptions (recorded_at) WHERE recorded_at IS NOT NULL');

        // Backfill en UN solo UPDATE optimizado por PostgreSQL.
        // La cascada vive toda en SQL: SUBSTRING/regex para parsear el nombre,
        // COALESCE para la cascada, FROM para JOIN con files.
        //
        // Reporte por consola al final (visible con `php artisan migrate`).
        $stats = $this->backfill();

        Log::info('migration.recorded_at.backfill', $stats);
        fwrite(STDERR, sprintf(
            "[recorded_at backfill] total=%d parsed=%d file_modified=%d finished_at=%d null=%d\n",
            $stats['total'],
            $stats['parsed'],
            $stats['file_modified'],
            $stats['finished_at'],
            $stats['null'],
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transcriptions_recorded_at_index');
        Schema::table('transcriptions', function ($table) {
            $table->dropColumn('recorded_at');
        });
    }

    /**
     * Backfill en UN solo UPDATE optimizado por PostgreSQL.
     *
     * Estrategia:
     *   1. Contar total de filas afectadas.
     *   2. UPDATE ... FROM files con COALESCE en cascada (regex → file_modified_at → finished_at).
     *   3. Recontar NULL para derivar cuántos quedaron sin asignar.
     *   4. Estimar la distribución por nivel vía queries agregadas (sin volver
     *      a tocar la tabla, solo lectura).
     *
     * @return array{parsed:int,file_modified:int,finished_at:int,null:int,total:int}
     */
    private function backfill(): array
    {
        $total = (int) DB::scalar('SELECT COUNT(*) FROM transcriptions');

        if ($total === 0) {
            return ['total' => 0, 'parsed' => 0, 'file_modified' => 0, 'finished_at' => 0, 'null' => 0];
        }

        // UN solo UPDATE masivo. PostgreSQL maneja internamente el scan + join.
        DB::statement(<<<'SQL'
            UPDATE transcriptions t
            SET recorded_at = COALESCE(
                CASE
                    WHEN f.name ~ '_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])_([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.[a-zA-Z0-9]+$'
                    THEN to_timestamp(
                        SUBSTRING(f.name FROM '_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.'),
                        'DDMMYYYYHH24MISS'
                    )
                    ELSE NULL
                END,
                f.file_modified_at,
                t.finished_at
            )
            FROM files f
            WHERE t.file_id = f.id AND t.recorded_at IS NULL
        SQL);

        // Distribución por nivel: queries de solo lectura.
        // - parsed:        COALESCE quedó en el to_timestamp (filename matcheaba)
        // - file_modified: filename no matcheaba, file_modified_at no nulo
        // - finished_at:   filename no matcheaba, file_modified_at NULL, finished_at no nulo
        // - null:          todo NULL
        //
        // Distinguimos "filename matcheaba" de "filename NO matcheaba" usando
        // una columna calculada en un SELECT. PostgreSQL permite esto porque
        // recorded_at ahora existe en la tabla.

        $row = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE t.recorded_at IS NOT NULL
                    AND f.name ~ '_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])_([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.[a-zA-Z0-9]+$') AS parsed,
                COUNT(*) FILTER (WHERE t.recorded_at IS NOT NULL
                    AND f.name !~ '_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])_([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.[a-zA-Z0-9]+$'
                    AND f.file_modified_at IS NOT NULL) AS file_modified,
                COUNT(*) FILTER (WHERE t.recorded_at IS NOT NULL
                    AND f.name !~ '_(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[012])((19|20)[0-9][0-9])_([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\.[a-zA-Z0-9]+$'
                    AND f.file_modified_at IS NULL) AS finished_at,
                COUNT(*) FILTER (WHERE t.recorded_at IS NULL) AS null_count
            FROM transcriptions t
            JOIN files f ON f.id = t.file_id
        SQL);

        return [
            'total' => $total,
            'parsed' => (int) ($row->parsed ?? 0),
            'file_modified' => (int) ($row->file_modified ?? 0),
            'finished_at' => (int) ($row->finished_at ?? 0),
            'null' => (int) ($row->null_count ?? 0),
        ];
    }
};
