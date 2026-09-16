<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restaura el indice unico sobre (storage_provider_id, path).
 *
 * Historia:
 *   2024_01_01_000004  crea files.path UNIQUE (global, demasiado estricto)
 *   2026_05_13_000002  lo sustituye por UNIQUE (path, storage_provider_id)
 *   2026_05_21_000002  lo ELIMINA con el comentario "167MB unused index:
 *                      0 scans recorded, no app-level dependency"
 *
 * Ese razonamiento fue erroneo: ninguna consulta lo usaba para LEER, pero era lo
 * unico que garantizaba la correccion. Sin el, el 2026-07-27 se insertaron 70.804
 * filas duplicadas (hasta 36 copias del mismo archivo) cuando un montaje NFS
 * caido provoco el borrado del arbol y una estampida de re-escaneos concurrentes.
 *
 * Requiere que `files:dedupe` se haya ejecutado: Postgres no admite NOT VALID
 * para indices unicos, asi que o se construye sobre datos limpios o falla.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY no puede ejecutarse dentro de una transaccion, y
     * Laravel envuelve las migraciones de Postgres en una por defecto.
     */
    public $withinTransaction = false;

    private const INDEX = 'files_storage_provider_id_path_unique';

    public function up(): void
    {
        // Un intento previo fallido deja el indice en estado INVALID: limpiarlo.
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);

        $dups = (int) DB::selectOne("
            select coalesce(sum(c - 1), 0) as n
            from (select count(*) c from files group by storage_provider_id, path) t
        ")->n;

        if ($dups > 0) {
            throw new RuntimeException(
                "No se puede crear el indice unico: quedan {$dups} filas duplicadas. "
                . 'Ejecutar primero `php artisan files:dedupe`.'
            );
        }

        // CONCURRENTLY evita el lock SHARE que bloquearia toda escritura en
        // `files` durante la construccion.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY ' . self::INDEX . ' ON files (storage_provider_id, path)');

        $valid = DB::selectOne(
            "select indisvalid from pg_index where indexrelid = ?::regclass",
            [self::INDEX]
        );

        if (!$valid || !$valid->indisvalid) {
            DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);

            throw new RuntimeException(
                'La construccion concurrente dejo un indice INVALID: aparecieron duplicados a mitad. '
                . 'Volver a ejecutar `files:dedupe` y reintentar.'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }
};
