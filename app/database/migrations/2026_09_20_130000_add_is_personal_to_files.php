<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `is_personal` column back to `files`.
 *
 * Background: la migration 2026_09_04_210000_normalize_storage_schema dropeó
 * `files.is_personal` porque el dato se podía derivar de `storage_providers.is_personal`
 * vía trigger. Sin embargo, el código del módulo Mis Archivos revertido a agosto
 * (commit bb71b9c) ESCRIBE `is_personal` en cada INSERT/UPDATE de `files`
 * (ver `StorageSyncService::createFileFromScan()` y `FileRegistry::ensure()`).
 *
 * Restaurar la columna + índice es la opción mínima para que el sync no falle
 * con `SQLSTATE[42703]: column "is_personal" does not exist`.
 *
 * El default es `false`: las filas existentes son por defecto no-personales.
 * Las filas personales nuevas las setea el código según el storage del que vienen.
 *
 * El down() restaura la columna con su default. La columna existía originalmente
 * en 2024-01-01 con `boolean DEFAULT false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->boolean('is_personal')->default(false)->after('is_folder');
        });

        // Índice condicional para que queries sobre archivos personales sean O(personal_rows)
        // en vez de O(files). Mismo patrón que tenía antes del 2026-09-04 drop.
        DB::statement('CREATE INDEX idx_files_personal ON files (owner_id, is_personal) WHERE is_personal = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_personal');
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('is_personal');
        });
    }
};
