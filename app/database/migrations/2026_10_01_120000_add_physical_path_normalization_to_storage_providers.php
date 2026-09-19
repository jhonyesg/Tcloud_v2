<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-storage-physical-path-normalization` (Stage 1):
 *
 * Normalización de la identidad física del storage: cada `base_path`
 * normalizado (lower(rtrim(., '/'))) corresponde a UN storage row canónico
 * en `storage_providers`. Hasta hoy el schema permitía duplicados: 8 pares
 * de storages apuntan al mismo path en disco (caso real: storage 132 + 142
 * ambos en `/Tcloud/.../La_voz/`). Esto produce duplicación de filas en
 * `files`, ambigüedad en `findMoreSpecificStorage` y `currentListing`, y
 * drift de FKs aguas abajo.
 *
 * Stage 1 (esta migration): añade la columna `physical_path_normalized`
 * (STORED generated) + columnas `merged_into_id`/`merged_at`/`merged_reason`
 * + índice no-unique. NO añade UNIQUE constraint todavía — eso es Stage 3,
 * tras mergear los 8 pares vía `storages:merge-duplicates`.
 *
 * Notas operativas:
 *  - La columna STORED se calcula al vuelo en INSERT/UPDATE. Costo
 *    despreciable: los writes a `storage_providers.base_path` son raros
 *    (admin-only), y el index mantiene la unicidad de la búsqueda por path.
 *  - `merged_into_id` permite soft-delete: el storage row sigue existiendo
 *    para auditoría pero queda invisible al sync. `ON DELETE SET NULL`
 *    evita ciclos si se borra el canonical.
 *  - El down() borra todo. La columna STORED se borra sin problema.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) physical_path_normalized: STORED generated column
        DB::statement(<<<'SQL'
            ALTER TABLE storage_providers
            ADD COLUMN physical_path_normalized varchar(500)
            GENERATED ALWAYS AS (
                CASE
                    WHEN base_path IS NULL OR base_path = '' THEN NULL
                    ELSE lower(rtrim(base_path, '/'))
                END
            ) STORED
        SQL);

        // 2) Index (not unique — UNIQUE comes in Stage 3 after operator merges)
        DB::statement(<<<'SQL'
            CREATE INDEX storage_providers_physical_path_normalized_idx
            ON storage_providers (kind, physical_path_normalized)
            WHERE physical_path_normalized IS NOT NULL
        SQL);

        // 3) Merge tracking columns
        DB::statement(<<<'SQL'
            ALTER TABLE storage_providers
            ADD COLUMN merged_into_id bigint NULL,
            ADD COLUMN merged_at timestamp NULL,
            ADD COLUMN merged_reason text NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE storage_providers
            ADD CONSTRAINT storage_providers_merged_into_id_fkey
            FOREIGN KEY (merged_into_id) REFERENCES storage_providers(id)
            ON DELETE SET NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX storage_providers_merged_into_id_idx
            ON storage_providers (merged_into_id)
            WHERE merged_into_id IS NOT NULL
        SQL);

        // 4) Audit log table for merges
        DB::statement(<<<'SQL'
            CREATE TABLE storage_merges (
                id bigserial PRIMARY KEY,
                canonical_id bigint NOT NULL REFERENCES storage_providers(id) ON DELETE CASCADE,
                duplicate_id bigint NOT NULL REFERENCES storage_providers(id) ON DELETE CASCADE,
                files_moved int NOT NULL DEFAULT 0,
                files_deduped int NOT NULL DEFAULT 0,
                fk_tables_affected jsonb NOT NULL DEFAULT '{}'::jsonb,
                executed_by_user_id bigint NULL REFERENCES users(id) ON DELETE SET NULL,
                executed_at timestamp NOT NULL DEFAULT now(),
                notes text NULL,
                CONSTRAINT storage_merges_distinct CHECK (canonical_id <> duplicate_id),
                CONSTRAINT storage_merges_canonical_smaller CHECK (canonical_id < duplicate_id)
            )
        SQL);

        DB::statement('CREATE INDEX storage_merges_canonical_idx ON storage_merges (canonical_id)');
        DB::statement('CREATE INDEX storage_merges_duplicate_idx ON storage_merges (duplicate_id)');
        DB::statement('CREATE INDEX storage_merges_executed_at_idx ON storage_merges (executed_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS storage_merges');
        DB::statement('ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_merged_into_id_fkey');
        DB::statement('DROP INDEX IF EXISTS storage_providers_merged_into_id_idx');
        DB::statement('ALTER TABLE storage_providers DROP COLUMN IF EXISTS merged_reason');
        DB::statement('ALTER TABLE storage_providers DROP COLUMN IF EXISTS merged_at');
        DB::statement('ALTER TABLE storage_providers DROP COLUMN IF EXISTS merged_into_id');
        DB::statement('DROP INDEX IF EXISTS storage_providers_physical_path_normalized_idx');
        DB::statement('ALTER TABLE storage_providers DROP COLUMN IF EXISTS physical_path_normalized');
    }
};
