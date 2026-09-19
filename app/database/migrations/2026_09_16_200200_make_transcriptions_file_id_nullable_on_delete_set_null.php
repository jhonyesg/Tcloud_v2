<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * transcriptor-physical-file-identity (D6): el ciclo de vida de una
 * transcripcion deja de colgar del borrado en Mis Archivos.
 *
 * Antes: `transcriptions.file_id` era NOT NULL + ON DELETE CASCADE. Borrar un
 * archivo en Mis Archivos destruia la transcripcion, sus segmentos, sus
 * keyword matches y sus alert logs. El transcriptor no puede tener soberania
 * sobre su ciclo de vida mientras cuelgue del borrado de otro modulo.
 *
 * `PapeleraService::isFileLinked()` ya consultaba `transcriptions` para
 * defenderse de esto — es la confesion del acoplamiento. `FileController::
 * deleteFile()` no consulta.
 *
 * Ahora: file_id nullable + ON DELETE SET NULL. El resultado transcrito
 * sobrevive al borrado del archivo; `source_absolute_path` (migracion
 * 2026_09_16_200100) conserva la ruta como identificador estable, asi que la
 * reconciliacion reutiliza la fila por ruta si el archivo reaparece.
 *
 * El UNIQUE (file_id) existente sigue siendo valido: en Postgres multiples
 * NULL no colisionan entre si.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transcriptions ALTER COLUMN file_id DROP NOT NULL');

        DB::statement(<<<'SQL'
ALTER TABLE transcriptions
  DROP CONSTRAINT IF EXISTS transcriptions_file_id_foreign
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE transcriptions
  DROP CONSTRAINT IF EXISTS transcriptions_file_id_fkey
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE transcriptions
  ADD CONSTRAINT transcriptions_file_id_foreign
  FOREIGN KEY (file_id) REFERENCES files(id)
  ON DELETE SET NULL
SQL);
    }

    public function down(): void
    {
        // Solo se puede volver a CASCADE + NOT NULL si no quedaron filas
        // huerfanas por el camino (archivo borrado durante la vigencia del
        // SET NULL). Si las hay, el rollback debe resolverlas primero: el
        // operador decide si las borra o las re-vincula.
        $huerfanas = DB::table('transcriptions')->whereNull('file_id')->count();

        if ($huerfanas > 0) {
            throw new RuntimeException(
                "No se puede revertir: hay {$huerfanas} transcriptions con file_id NULL "
                . '(archivos borrados en Mis Archivos durante la vigencia del SET NULL). '
                . 'Resolverlas antes (borrarlas o re-vincularlas) y volver a correr el rollback.'
            );
        }

        DB::statement(<<<'SQL'
ALTER TABLE transcriptions
  DROP CONSTRAINT IF EXISTS transcriptions_file_id_foreign
SQL);

        DB::statement('ALTER TABLE transcriptions ALTER COLUMN file_id SET NOT NULL');

        DB::statement(<<<'SQL'
ALTER TABLE transcriptions
  ADD CONSTRAINT transcriptions_file_id_foreign
  FOREIGN KEY (file_id) REFERENCES files(id)
  ON DELETE CASCADE
SQL);
    }
};
