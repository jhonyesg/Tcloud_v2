<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-files-physical-folder-identity` (Stage 1):
 *
 * Añade a `files` la columna `merged_into_id` y metadatos de auditoría para
 * modelar la identidad física de un folder cuando existe como mirror en
 * un storage padre y como canónico en un sub-storage más específico.
 *
 * Caso raíz (2026-09-17): 27.922 pares folder espejo detectados por el spike
 * `files:repair-folder-mirrors --dry-run`. 36 folder shares apuntan al mirror
 * (parent view) en lugar del canónico; 35 de esos 36 se repuntan en PR 1.
 *
 * Notas operativas:
 *  - `merged_into_id` permite soft-link: el mirror row sigue existiendo pero
 *    queda enlazado al canónico. `ON DELETE SET NULL` evita ciclos si el
 *    canónico se borra (ej. via `storages:merge-duplicates`).
 *  - `base_path_snapshot` es denormalización de `storage_providers.base_path`.
 *    El observer `FileObserver::saved` lo mantiene en sincronía. Un comando
 *    artisan `files:resync-base-path-snapshots` cubre batch repair.
 *  - El UNIQUE constraint sobre `physical_path_normalized` se difiere a
 *    Stage 3 de este change, tras el backfill masivo.
 *
 * Reversible: el down() borra índice + columnas en orden inverso.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) base_path_snapshot: copia denormalizada del base_path del storage.
        //    Nullable porque files sin storage_provider_id (huérfanos legacy)
        //    no tienen snapshot. Largo 500 igual que storage_providers.base_path.
        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD COLUMN base_path_snapshot varchar(500) NULL
        SQL);

        // 2) merged_into_id: FK self-ref con SET NULL.
        //    Un folder row sin merged_into_id es "canónico".
        //    Un folder row con merged_into_id es "mirror".
        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD COLUMN merged_into_id bigint NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD CONSTRAINT files_merged_into_id_fkey
            FOREIGN KEY (merged_into_id) REFERENCES files(id)
            ON DELETE SET NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX files_merged_into_id_idx
            ON files (merged_into_id)
            WHERE merged_into_id IS NOT NULL
        SQL);

        // 3) Metadatos de auditoría de la operación de link.
        //    merged_at: cuándo se hizo el link. merged_reason: por qué.
        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD COLUMN merged_at timestamp NULL,
            ADD COLUMN merged_reason varchar(50) NULL
        SQL);

        // 4) Índice de soporte para los comandos artisan: detección de pares
        //    por (storage_provider_id, base_path_snapshot) sin escaneo completo.
        DB::statement(<<<'SQL'
            CREATE INDEX files_storage_base_path_snapshot_idx
            ON files (storage_provider_id, base_path_snapshot)
            WHERE base_path_snapshot IS NOT NULL AND is_folder = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS files_storage_base_path_snapshot_idx');
        DB::statement('DROP INDEX IF EXISTS files_merged_into_id_idx');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_merged_into_id_fkey');
        DB::statement('ALTER TABLE files DROP COLUMN IF EXISTS merged_reason');
        DB::statement('ALTER TABLE files DROP COLUMN IF EXISTS merged_at');
        DB::statement('ALTER TABLE files DROP COLUMN IF EXISTS merged_into_id');
        DB::statement('ALTER TABLE files DROP COLUMN IF EXISTS base_path_snapshot');
    }
};