<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `solicitud-puntual-tv-radio` (T1 — Grupo 1: Fundaciones de esquema).
 *
 * Índice único PARCIAL sobre `(user_id, medio_id, fecha, hora_inicio,
 * hora_fin)` filtrado por `status IN ('ready', 'partial')`.
 *
 * Por qué: el invariante "una solicitud viva por (usuario, medio, fecha y
 * rango)" solo estaba garantizado por una consulta previa. Dos POST
 * simultáneos para el mismo rango pasaban los dos esa consulta y los dos
 * insertaban: quedaban DOS juegos de enlaces públicos al mismo material y
 * el control contable contaba lo pedido dos veces. Con el índice, el segundo
 * INSERT viola la unicidad y el servicio devuelve la solicitud que ganó
 * (captura del 23505).
 *
 * Es parcial a propósito: el histórico conserva las solicitudes revocadas y
 * no disponibles de ese mismo rango, y varias pueden coexistir sin
 * conflicto. Una solicitud revocada tampoco bloquea una nueva: el cliente
 * puede volver a pedir el mismo material.
 *
 * Reversible: el down() elimina el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_solicitud_puntual_unique_live
            ON solicitud_puntual (user_id, medio_id, fecha, hora_inicio, hora_fin)
            WHERE status IN ('ready', 'partial')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_solicitud_puntual_unique_live');
    }
};
