<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transcriptor-physical-file-identity (D3): identidad fisica del archivo.
 *
 * `transcriptions.file_id` es UNIQUE, o sea una transcripcion por FILA de
 * `files`. Como Mis Archivos modela N filas por archivo fisico (una por gestor
 * que lo contiene), el mismo audio puede generar dos transcripciones y DOS
 * ENVIOS al upstream: ffmpeg y tokens pagados dos veces. Medido 2026-09-16:
 * 2 duplicados confirmados y 12.910 archivos fisicos en riesgo.
 *
 * `source_absolute_path` = rtrim(storage_providers.base_path,'/') . '/' . files.path
 * del dueño efectivo. Es el identificador ESTABLE: sobrevive a que el operador
 * cambie transcription_enabled de un gestor, y sobrevive al borrado de la fila
 * `files` (ver migracion 2026_09_16_200200, que rompe el CASCADE).
 *
 * El indice UNIQUE es GLOBAL (no parcial por estado) porque el sistema ya
 * reintenta in-place: --include-failed y --include-done resetean la MISMA fila
 * a pending en lugar de crear otra. El candado hace el doble pago imposible,
 * no solo improbable.
 *
 * NO se puebla aqui: lo hace `transcription:reconcile-physical-identities`,
 * que es auditable e idempotente (dry-run por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('transcriptions', 'source_absolute_path')) {
            Schema::table('transcriptions', function (Blueprint $table) {
                $table->string('source_absolute_path', 700)->nullable()->after('file_id');
            });
        }

        // Indice parcial: solo filas con valor. Evita el ruido de los NULL
        // mientras la reconciliacion no ha corrido y permite convivir con
        // filas pendientes de rellenar.
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS transcriptions_source_abs_path_unique
  ON transcriptions (source_absolute_path)
  WHERE source_absolute_path IS NOT NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transcriptions_source_abs_path_unique');

        Schema::table('transcriptions', function (Blueprint $table) {
            if (Schema::hasColumn('transcriptions', 'source_absolute_path')) {
                $table->dropColumn('source_absolute_path');
            }
        });
    }
};
