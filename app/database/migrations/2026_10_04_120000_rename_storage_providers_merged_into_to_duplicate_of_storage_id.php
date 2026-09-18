<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `schema-clarity-rename-merged-into` (Task 2.2):
 *
 * Renombra `storage_providers.merged_into_id` → `duplicate_of_storage_id`
 * para desambiguar de `files.merged_into_id` (que mide folders espejo, no
 * duplicados de storage). El FK constraint y el índice parcial siguen al
 * nuevo nombre.
 *
 * Atomicidad: PG permite `ALTER TABLE ... RENAME COLUMN` dentro de una TX;
 * envolver en `DB::transaction()` garantiza rollback completo si algo
 * falla. Los nombres viejos (`merged_into_id`, FK constraint,
 * `merged_into_id_idx`) se restauran en el `down()`.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE storage_providers RENAME COLUMN merged_into_id TO duplicate_of_storage_id');
            DB::statement('ALTER TABLE storage_providers RENAME CONSTRAINT storage_providers_merged_into_id_fkey TO storage_providers_duplicate_of_storage_id_fkey');
            DB::statement('ALTER INDEX storage_providers_merged_into_id_idx RENAME TO storage_providers_duplicate_of_storage_id_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE storage_providers RENAME COLUMN duplicate_of_storage_id TO merged_into_id');
            DB::statement('ALTER TABLE storage_providers RENAME CONSTRAINT storage_providers_duplicate_of_storage_id_fkey TO storage_providers_merged_into_id_fkey');
            DB::statement('ALTER INDEX storage_providers_duplicate_of_storage_id_idx RENAME TO storage_providers_merged_into_id_idx');
        });
    }
};