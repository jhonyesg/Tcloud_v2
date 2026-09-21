<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore Mis Archivos al baseline de agosto 2026 (commit bb71b9c).
 *
 * Change: restore-mis-archivos-august (openspec/changes/restore-mis-archivos-august/).
 *
 * Pasos del `up()`:
 *
 * 1. DELETE de las 1.454.006 mirror rows. Esto dispara los FKs:
 *    - transcriptions.file_id: ON DELETE SET NULL → 78.480 tx quedan con file_id=NULL (OK, no perdemos srt_content).
 *    - shares.file_id: ON DELETE CASCADE → 33 shares se eliminan (operador aprobó CASCADE el 2026-09-20).
 *
 * 2. DROP de las 11 columnas agregadas desde septiembre 2026 que NO existían
 *    en `files` el 21-ago-2026 (ver backup `backups/restore-august-pre-*.sql.gz`).
 *
 * 3. DROP de las 3 tablas auxiliares creadas en septiembre:
 *    - file_mirror_audit_log (append-only de mirrors)
 *    - files_owner_canonical_audit (log de canonical owner)
 *    - files_prune_batches (auditoría de purgas — parte de files_storages_coupling Sept 5)
 *
 * El `down()` recrea columnas y tablas pero NO restaura las 1.454.006 mirror
 * rows borradas — para eso está `backups/restore-august-mirror-rows-snapshot-*.sql.gz`
 * y `backups/restore-august-data-*.sql.gz`. Ver design.md §Migration Plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NOTA sobre limpieza: este change NO borra las mirror rows todavía.
        // Aplicamos el principio "agosto primero, limpieza después" (ver
        // design.md §Decisions). Las 1.454.006 mirror rows siguen en `files`
        // como filas ordinarias (sin canonical_folder_id). El operador
        // ejecuta la limpieza por separado, sin prisa, vía el script de
        // §Post-deploy cleanup.

        // (1) Drop FK self-ref que sale de canonical_folder_id (debe ir ANTES del dropColumn)
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_canonical_folder_id_fkey');

        // (3) Drop indexes que no se borran automáticamente con la columna
        DB::statement('DROP INDEX IF EXISTS idx_files_availability_state');
        DB::statement('DROP INDEX IF EXISTS idx_files_missing_reconcile');
        DB::statement('DROP INDEX IF EXISTS files_trash_sweep_idx');
        DB::statement('DROP INDEX IF EXISTS files_canonical_folder_id_idx');
        DB::statement('DROP INDEX IF EXISTS files_storage_base_path_snapshot_idx');

        // (4) Drop las 10 columnas. Orden:
        //     - mirror (canonical_folder_id, merged_at, merged_reason)
        //     - cross-storage helpers (base_path_snapshot)
        //     - trash (is_trashed, deleted_at, original_parent_id)
        //     - availability (availability_state, last_verified_at, missing_since_at)
        //     NOTA: merged_into_id NO existe — fue renombrado a canonical_folder_id
        //     por la migration 2026_10_04_120100.
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn([
                'canonical_folder_id',
                'merged_at',
                'merged_reason',
                'base_path_snapshot',
                'original_parent_id',
                'is_trashed',
                'deleted_at',
                'availability_state',
                'last_verified_at',
                'missing_since_at',
            ]);
        });

        // (5) Drop de las 3 tablas auxiliares
        Schema::dropIfExists('file_mirror_audit_log');
        Schema::dropIfExists('files_owner_canonical_audit');
        Schema::dropIfExists('files_prune_batches');
    }

    public function down(): void
    {
        // Recrear las 3 tablas con schema mínimo (no idéntico al original —
        // este down() es para rollback del schema, los datos hay que
        // restaurarlos desde backups/restore-august-data-*.sql.gz).
        Schema::create('file_mirror_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mirror_file_id')->nullable();
            $table->unsignedBigInteger('canonical_file_id')->nullable();
            $table->string('action', 50);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('mirror_file_id')->references('id')->on('files')->onDelete('set null');
            $table->foreign('canonical_file_id')->references('id')->on('files')->onDelete('set null');
        });

        Schema::create('files_owner_canonical_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('storage_provider_id');
            $table->string('kind', 50);
            $table->string('action', 50);
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('files_prune_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('storage_provider_id');
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->jsonb('report');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Recrear las 11 columnas en files
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('canonical_folder_id')->nullable()->after('parent_id');
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('canonical_folder_id');
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');
            $table->string('merged_reason')->nullable()->after('merged_at');
            $table->string('base_path_snapshot', 500)->nullable()->after('is_personal');
            $table->unsignedBigInteger('original_parent_id')->nullable()->after('parent_id');
            $table->timestamp('deleted_at')->nullable()->after('file_modified_at');
            $table->boolean('is_trashed')->default(false)->after('deleted_at');
            $table->string('availability_state', 20)->nullable()->after('is_trashed');
            $table->timestamp('last_verified_at')->nullable()->after('availability_state');
            $table->timestamp('missing_since_at')->nullable()->after('last_verified_at');

            $table->foreign('canonical_folder_id')->references('id')->on('files')->onDelete('set null');
            $table->foreign('original_parent_id')->references('id')->on('files')->onDelete('set null');
        });

        DB::statement('CREATE INDEX idx_files_availability_state ON files (availability_state)');
        DB::statement('CREATE INDEX idx_files_missing_reconcile ON files (storage_provider_id, missing_since_at) WHERE availability_state IS NOT NULL');
        DB::statement('CREATE INDEX files_trash_sweep_idx ON files (deleted_at) WHERE is_trashed = true');
        DB::statement('CREATE INDEX files_canonical_folder_id_idx ON files (canonical_folder_id) WHERE canonical_folder_id IS NOT NULL');
        DB::statement('CREATE INDEX files_storage_base_path_snapshot_idx ON files (storage_provider_id, base_path_snapshot)');
    }
};
