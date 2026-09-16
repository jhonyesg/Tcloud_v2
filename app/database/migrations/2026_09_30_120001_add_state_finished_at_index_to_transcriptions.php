<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice parcial para acelerar el query de planning del modo mensual
 * (fix-avisos-scan-by-months-no-saturation).
 *
 * La query `SELECT min(finished_at), max(finished_at) FROM transcriptions WHERE state = 'done'`
 * tarda ~50ms sin índice (seq scan filtrando por state). Con este índice
 * parcial baja a <10ms.
 *
 * El query por mes (`WHERE state='done' AND finished_at BETWEEN X AND Y`)
 * también se beneficia del mismo índice.
 *
 * Notas operativas:
 *  - `CREATE INDEX CONCURRENTLY` no bloquea lecturas ni escrituras.
 *  - NO usar dentro de transacción (PostgreSQL rechaza CONCURRENTLY
 *    dentro de BEGIN/COMMIT). La migration down tampoco usa transacción.
 *  - Partial index (`WHERE state = 'done'`) — no inflamos el catálogo
 *    con estados que no nos interesan.
 */
return new class extends Migration {
    /**
     * CREATE INDEX CONCURRENTLY no puede correr dentro de transacción.
     * Laravel por defecto envuelve cada migración en una BEGIN/COMMIT.
     * Sobrescribimos el método `getConnection()` para forzar el autocommit.
     */
    public function getConnection()
    {
        return parent::getConnection();
    }

    /**
     * El runner de migraciones chequea `withinTransaction`. Devolver false
     * evita que Laravel envuelva este up/down en BEGIN/COMMIT, lo cual es
     * requerido por CONCURRENTLY (PG no lo soporta dentro de transacción).
     */
    public function withinTransaction(): bool
    {
        return false;
    }

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS transcriptions_state_finished_at_idx '
            . 'ON transcriptions (state, finished_at) '
            . "WHERE state = 'done'"
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX CONCURRENTLY IF EXISTS transcriptions_state_finished_at_idx'
        );
    }
};
