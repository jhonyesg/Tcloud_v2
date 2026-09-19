<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `files-canonical-owner-by-storage` (2026-09-18):
 *
 * Aplica la migracion canonica de `files.owner_id` reasignando cada file al
 * owner canonico de su storage (helper `StorageProvider::canonicalOwnerId()`).
 *
 * La migracion:
 *  1. Captura snapshot en `files_owner_canonical_audit` (filas a modificar).
 *  2. UPDATE set-based con `session_replication_role = replica` para evitar
 *     evaluacion per-row de FK triggers (~30-90s para 2.26M filas).
 *  3. Excluye files trashados / soft-deleted (la papelera conserva owner_id).
 *
 * Reversibilidad: `down()` restaura `files.owner_id` desde
 * `files_owner_canonical_audit` usando un JOIN por file_id. Luego trunca la
 * tabla de auditoria.
 *
 * Para cambios massivos sin downtime perceptible, esta migracion corre dentro
 * de una transaccion unica; si algo falla, rollback automatico.
 *
 * Si la BD tiene `files_owner_canonical_audit` previa de una corrida abortada,
 * se trunca primero para evitar duplicados.
 */
return new class extends Migration {
    public function up(): void
    {
        Artisan::call('tcloud:files-canonicalize', ['--apply' => true]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('files_owner_canonical_audit')) {
            return;
        }

        $count = (int) DB::table('files_owner_canonical_audit')->count();
        if ($count === 0) {
            return;
        }

        DB::beginTransaction();
        try {
            DB::statement('SET LOCAL session_replication_role = replica');
            DB::statement('SET LOCAL statement_timeout = 0');
            DB::statement('SET LOCAL lock_timeout = 0');

            // Restaurar old_owner_id desde la auditoria
            $updated = DB::affectingStatement("
                UPDATE files f
                SET owner_id = a.old_owner_id
                FROM files_owner_canonical_audit a
                WHERE f.id = a.file_id
            ");

            DB::table('files_owner_canonical_audit')->truncate();
            DB::commit();

            echo "Rollback completo: {$updated} files.owner_id restaurados.\n";
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
};
