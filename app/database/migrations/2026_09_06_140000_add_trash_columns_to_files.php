<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papelera de reciclaje — soft-delete + restore.
 *
 * 1. files.deleted_at (NUEVO): timestamp del momento en que se trashó.
 * 2. files.is_trashed (NUEVO): flag explícito. Alternativa al calculo derivado
 *    de deleted_at IS NOT NULL para que los indices y queries sean simples.
 * 3. files.original_parent_id (NUEVO): parent original antes de trashing, para
 *    restaurar al lugar de origen cuando el padre sigue existiendo. ON DELETE
 *    SET NULL: si el padre se purga antes que el hijo, no queremos orphan FK.
 * 4. files_trash_sweep_idx: indice parcial sobre las filas trashadas para que
 *    el cron trash:purge escanee SOLO la fraccion relevante aunque la tabla
 *    files tenga millones de filas.
 *
 * Default is_trashed=false y deleted_at NULL: la migracion es NO destructiva
 * (no toca filas existentes). Rollback con --step=1 deja la tabla igual que
 * antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('file_modified_at');
            $table->boolean('is_trashed')->default(false)->after('deleted_at');
            $table->unsignedBigInteger('original_parent_id')->nullable()->after('parent_id');

            // FK al padre original. Si el padre se purga antes que el hijo,
            // el campo queda NULL (no rompe restore — solo va a root).
            $table->foreign('original_parent_id')
                ->references('id')->on('files')
                ->onDelete('set null');
        });

        // Indice parcial: solo las filas trashadas. Mantiene el barrido de
        // purga O(retention) aunque la tabla tenga 700k filas activas.
        DB::statement('CREATE INDEX files_trash_sweep_idx ON files (deleted_at) WHERE is_trashed = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS files_trash_sweep_idx');

        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['original_parent_id']);
            $table->dropColumn(['deleted_at', 'is_trashed', 'original_parent_id']);
        });
    }
};
