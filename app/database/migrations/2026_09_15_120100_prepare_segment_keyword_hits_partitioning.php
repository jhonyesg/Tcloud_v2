<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * segment-keyword-hits-partitioning (scaffolding, NO activa la partición).
 *
 * Volumen actual: 12,520 filas / 4.5 MB (verificado 2026-09-15). Trigger para
 * particionar: > 10M filas o > 1 GB.
 *
 * Esta migración deja:
 *  - Comentario sobre la tabla con la estrategia y el threshold.
 *  - Función `segment_keyword_hits_create_partition(month)` reutilizable.
 *
 * Activación real (cuando se supere el threshold) requiere change separado
 * con ventana de mantenimiento porque PostgreSQL no permite ALTER TABLE de
 * tabla plana → PARTICIONADA; hay que DROP/CREATE + COPY desde backup.
 *
 * Operación de partición futura (NO ejecutada aquí):
 *   SELECT segment_keyword_hits_create_partition('2026_09');
 *
 * DROP PARTITION para retención (cuando se decida):
 *   DROP TABLE segment_keyword_hits_2026_06;  -- O(1)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Comentario persistente sobre la tabla con estrategia + threshold.
        DB::statement("
            COMMENT ON TABLE segment_keyword_hits IS
            'Menciones materializadas. Volumen actual 12k filas / 4.5 MB. '
            'THRESHOLD para particionamiento: > 10M filas o > 1 GB. '
            'Estrategia: RANGE mensual sobre matched_at. '
            'Activación vía: SELECT segment_keyword_hits_create_partition(''YYYY_MM''); '
            'Retención O(1) vía: DROP TABLE segment_keyword_hits_YYYY_MM;'
        ");

        // Función helper reutilizable: idempotente (verifica existencia antes).
        DB::statement("
            CREATE OR REPLACE FUNCTION segment_keyword_hits_create_partition(
                p_month text
            ) RETURNS text AS $$
            DECLARE
                v_partition_name text;
                v_from text;
                v_to text;
            BEGIN
                v_partition_name := 'segment_keyword_hits_' || replace(p_month, '-', '_');
                v_from := p_month || '-01';
                v_to   := to_char((p_month || '-01')::date + interval '1 month', 'YYYY-MM-DD');

                IF EXISTS (
                    SELECT 1 FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = v_partition_name
                      AND n.nspname = current_schema()
                ) THEN
                    RETURN 'EXISTS: ' || v_partition_name;
                END IF;

                -- Esta función ASUME que segment_keyword_hits ya es una tabla
                -- PARTICIONADA. Si NO lo es aún, devuelve INSTRUCTIONS.
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class c
                    WHERE c.relname = 'segment_keyword_hits'
                      AND c.relkind IN ('p', 'r')
                      AND c.relpartbound IS NOT NULL
                ) THEN
                    RETURN 'SKIPPED: segment_keyword_hits is not yet partitioned. '
                        || 'Run actual partition migration (change separado) first.';
                END IF;

                EXECUTE format(
                    'CREATE TABLE %I PARTITION OF segment_keyword_hits FOR VALUES FROM (%L) TO (%L)',
                    v_partition_name, v_from, v_to
                );
                RETURN 'CREATED: ' || v_partition_name;
            END;
            $$ LANGUAGE plpgsql
        ");
    }

    public function down(): void
    {
        DB::statement("DROP FUNCTION IF EXISTS segment_keyword_hits_create_partition(text)");
        DB::statement("COMMENT ON TABLE segment_keyword_hits IS NULL");
    }
};
