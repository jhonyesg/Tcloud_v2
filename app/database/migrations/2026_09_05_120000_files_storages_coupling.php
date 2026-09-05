<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acoplamiento Files ↔ Storages con purga FK-aware.
 *
 * 1. storage_providers.kind (NUEVO): enum local/external. Backfill desde
 *    STORAGE_SYNC_EXPECTED_MOUNTS.
 * 2. files.availability_state (AMPLIADO): nuevos valores 'missing' y 'gone'.
 *    CHECK recreado. NO se renombran los valores actuales.
 * 3. files.missing_since_at: ya existe (2026_05_13_000003 + siguientes), ahora
 *    se usa de verdad.
 * 4. idx_files_missing_reconcile: índice parcial sobre los estados terminales
 *    para que el reconciliador haga scan acotado por storage_provider_id.
 *
 * NO se modifica:
 *  - ON DELETE CASCADE en transcriptions/shares/media_edit_jobs.
 *  - Índice UNIQUE files_storage_provider_id_path_unique (memoria 2026-07-27).
 *  - Schema de storage_providers.type (local/s3) — kind es ADITIVO.
 */
return new class extends Migration
{
    private const KIND_LOCAL = 'local';
    private const KIND_EXTERNAL = 'external';

    public function up(): void
    {
        // 1. storage_providers.kind
        Schema::table('storage_providers', function ($table) {
            $table->string('kind', 16)
                ->default(self::KIND_LOCAL)
                ->after('type');
        });

        DB::statement(
            'ALTER TABLE storage_providers ADD CONSTRAINT storage_providers_kind_check '
            . "CHECK (kind IN ('" . self::KIND_LOCAL . "','" . self::KIND_EXTERNAL . "'))"
        );

        // Backfill desde STORAGE_SYNC_EXPECTED_MOUNTS. Si la config devuelve
        // vacío (entornos nuevos o sin env var), nada se mueve y todo queda local.
        $expected = (array) config('storage_sync.mounts.expected', []);
        $expected = array_values(array_filter(array_map('trim', $expected)));

        if ($expected !== []) {
            $placeholders = implode(',', array_fill(0, count($expected), '?'));
            DB::statement(
                "UPDATE storage_providers SET kind = '" . self::KIND_EXTERNAL . "' "
                . "WHERE base_path IN ({$placeholders})",
                $expected
            );
        }

        // 2. files.availability_state: ampliar CHECK para incluir 'missing' y 'gone'.
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check');
        DB::statement(
            "ALTER TABLE files ADD CONSTRAINT files_availability_state_check "
            . "CHECK (availability_state IN ('available','unknown','missing','gone'))"
        );

        // 3. Índice parcial para el reconciliador (queries acotadas por storage).
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_files_missing_reconcile '
            . 'ON files (storage_provider_id, availability_state) '
            . "WHERE availability_state IN ('unknown','missing','gone')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_missing_reconcile');

        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check');
        DB::statement(
            "ALTER TABLE files ADD CONSTRAINT files_availability_state_check "
            . "CHECK (availability_state IN ('available','unknown'))"
        );

        DB::statement('ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_kind_check');
        Schema::table('storage_providers', function ($table) {
            $table->dropColumn('kind');
        });
    }
};