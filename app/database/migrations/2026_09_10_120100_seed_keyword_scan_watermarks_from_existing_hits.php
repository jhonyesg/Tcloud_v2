<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * avisos-keyword-storage-watermark: seed inicial desde hits existentes.
 *
 * Para cada par (keyword_id, storage_provider_id) que YA tiene hits materializados,
 * infiere scanned_until = MIN(transcription.finished_at) - 1s. El "-1s" garantiza que
 * el siguiente cron re-evalúe AL MENOS una vez esa transcripción (defensa contra
 * falsos negativos por reglas que cambiaron o normalización que mejoró).
 *
 * Para cada par APLICABLE sin hits pero con al menos un usuario con transcription_access
 * en ese storage, inserta scanned_until = NULL → catch-up completo en la próxima corrida.
 *
 * Cardinalidad esperada: 100-200 filas. Sin re-escaneo: solo lectura de
 * segment_keyword_hits + transcriptions + files + users.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO keyword_scan_watermarks
                (keyword_id, storage_provider_id, scanned_until, last_hit_at,
                 candidates_total, hits_total, created_at, updated_at)
            SELECT
                h.keyword_id,
                f.storage_provider_id,
                (MIN(t.finished_at) - INTERVAL '1 second') AS scanned_until,
                MAX(h.matched_at) AS last_hit_at,
                COUNT(DISTINCT h.transcription_id) AS candidates_total,
                COUNT(*) AS hits_total,
                NOW(),
                NOW()
            FROM segment_keyword_hits h
            JOIN transcriptions t ON t.id = h.transcription_id
            JOIN files f ON f.id = t.file_id
            WHERE t.finished_at IS NOT NULL
            GROUP BY h.keyword_id, f.storage_provider_id
            ON CONFLICT (keyword_id, storage_provider_id) DO NOTHING
        ");

        DB::statement("
            INSERT INTO keyword_scan_watermarks
                (keyword_id, storage_provider_id, scanned_until,
                 candidates_total, hits_total, created_at, updated_at)
            SELECT
                k.id AS keyword_id,
                us.storage_provider_id,
                NULL AS scanned_until,
                0, 0, NOW(), NOW()
            FROM keywords k
            CROSS JOIN user_storages us
            JOIN user_alerts_inteligentes uai
                 ON uai.user_id = us.user_id AND uai.enabled = true
            JOIN user_keyword uk ON uk.keyword_id = k.id AND uk.user_id = us.user_id
            WHERE us.transcription_access = true
            ON CONFLICT (keyword_id, storage_provider_id) DO NOTHING
        ");
    }

    public function down(): void
    {
        DB::table('keyword_scan_watermarks')->truncate();
    }
};
