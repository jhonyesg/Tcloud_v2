<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change `fix-shares-module-regression` (Grupo 1 — Fundaciones de esquema).
 *
 * Restaura en `files` las tres columnas de disponibilidad del recurso que la
 * migration baseline `2026_09_20_120000_drop_mirror_columns_and_restore_baseline`
 * dropeó junto con la familia mirror/canónico.
 *
 * Contexto:
 *  - La spec `share-file-availability` exige que cada `File` relacionado con
 *    un share tenga un estado observable `unknown` | `available` | `missing`,
 *    más la fecha de última verificación.
 *  - El estado debe ser consultable con un predicado SQL, para que el filtro
 *    "Archivo no disponible" del panel de compartidos no exija sondear el
 *    disco en cada request.
 *
 * Notas de diseño:
 *  - `availability_state` es un dato DERIVADO de una sonda de filesystem, no
 *    catálogo. El módulo de compartidos lo escribe con un UPDATE acotado
 *    (design.md, decisión D2) y no crea ni modifica ningún otro atributo de
 *    la fila. La frontera de escritura de `files` (Mis Archivos es el único
 *    módulo que crea/actualiza catálogo) se mantiene intacta.
 *  - `timestamptz` para las dos fechas porque representan instantes, no horas
 *    de pared. La app escribe con offset explícito para no depender del
 *    timezone de la sesión PostgreSQL (ver AGENTS.md, zona horaria PG).
 *  - Las columnas son NULLABLE y sin DEFAULT: `ADD COLUMN` sin default no
 *    reescribe la tabla (1.24M filas) en PostgreSQL.
 *  - El CHECK acota el dominio a los tres valores de la spec, para que un
 *    UPDATE con un valor inventado no contamine el filtro.
 *
 * Reversible: el down() elimina el CHECK, el índice y las tres columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('availability_state', 20)->nullable()->after('is_folder');
            $table->timestampTz('last_verified_at')->nullable()->after('availability_state');
            $table->timestampTz('missing_since_at')->nullable()->after('last_verified_at');
        });

        // CHECK de dominio: solo los tres estados de la spec.
        DB::statement(<<<'SQL'
            ALTER TABLE files
            ADD CONSTRAINT files_availability_state_check
            CHECK (
                availability_state IS NULL
                OR availability_state IN ('unknown', 'available', 'missing')
            )
        SQL);

        // Índice para el filtro de disponibilidad del panel de compartidos.
        // Parcial: la mayoría de las filas nunca se verifica, y el filtro
        // siempre consulta un valor concreto ('missing', 'unknown', ...).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_files_availability_state
            ON files (availability_state)
            WHERE availability_state IS NOT NULL
        SQL);

        // Índice auxiliar para la reconciliación de "ausente desde" (los
        // recursos confirmados ausentes y no reconciliados).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_files_missing_reconcile
            ON files (missing_since_at)
            WHERE missing_since_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_missing_reconcile');
        DB::statement('DROP INDEX IF EXISTS idx_files_availability_state');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_availability_state_check');

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn([
                'availability_state',
                'last_verified_at',
                'missing_since_at',
            ]);
        });
    }
};
