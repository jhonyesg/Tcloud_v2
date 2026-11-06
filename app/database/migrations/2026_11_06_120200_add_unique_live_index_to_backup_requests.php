<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `solicitud-backup-mis-archivos` (cierre de la revisión independiente).
 *
 * Índice único PARCIAL sobre `(user_id, source_storage_id, day)` filtrado por
 * `status = 'ready'`.
 *
 * Por qué: el invariante "un enlace vivo por (usuario, canal, día)" solo
 * estaba garantizado por una consulta previa (`findLive()`). Dos POST
 * simultáneos para el mismo día pasaban los dos esa consulta y los dos
 * insertaban: quedaban DOS shares y DOS enlaces públicos al master de
 * producción. Con el índice, el segundo INSERT viola la unicidad y el
 * servicio devuelve la solicitud que ganó (captura del 23505).
 *
 * Es parcial a propósito: el histórico conserva las solicitudes revocadas y
 * expiradas de ese mismo día, y varias pueden coexistir sin conflicto.
 *
 * Reversible: el down() elimina el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_backup_requests_unique_live
            ON backup_requests (user_id, source_storage_id, day)
            WHERE status = 'ready'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_backup_requests_unique_live');
    }
};
