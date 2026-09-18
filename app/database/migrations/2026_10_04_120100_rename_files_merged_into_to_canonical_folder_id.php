<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `schema-clarity-rename-merged-into` (Task 2.1):
 *
 * Renombra `files.merged_into_id` → `canonical_folder_id` para que el nombre
 * de la columna exprese sin ambigüedad que apunta al folder canónico del
 * cual esta fila es espejo. El FK constraint y el índice parcial siguen al
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
            DB::statement('ALTER TABLE files RENAME COLUMN merged_into_id TO canonical_folder_id');
            DB::statement('ALTER TABLE files RENAME CONSTRAINT files_merged_into_id_fkey TO files_canonical_folder_id_fkey');
            DB::statement('ALTER INDEX files_merged_into_id_idx RENAME TO files_canonical_folder_id_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE files RENAME COLUMN canonical_folder_id TO merged_into_id');
            DB::statement('ALTER TABLE files RENAME CONSTRAINT files_canonical_folder_id_fkey TO files_merged_into_id_fkey');
            DB::statement('ALTER INDEX files_canonical_folder_id_idx RENAME TO files_merged_into_id_idx');
        });
    }
};