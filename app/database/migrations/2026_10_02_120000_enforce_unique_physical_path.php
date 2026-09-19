<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `2026-09-17-storage-physical-path-normalization` (Stage 3):
 *
 * Activa el UNIQUE constraint `(kind, physical_path_normalized)` que la
 * Stage 1 dejo sin enforce. La migration ABORTA si quedan duplicados no
 * mergeados (`SystemSetting('storage.duplicates_remaining') > 0`), para
 * que el operador vea claramente cuantos pares faltan por mergear antes
 * de poder activar el enforcement.
 *
 * Uso operativo:
 *   1. Correr `php artisan storages:detect-duplicate-paths` para ver pares
 *   2. Mergear cada par via `php artisan storages:merge-duplicates --canonical=<id> --duplicate=<id> --apply`
 *   3. Re-correr detect para confirmar `duplicates_remaining = 0`
 *   4. Recién entonces correr `php artisan migrate` para activar el UNIQUE
 *
 * El constraint usa `NULLS NOT DISTINCT` (Postgres 15+): dos storages con
 * `physical_path_normalized IS NULL` NO entran en el constraint (compatibilidad
 * con storages config-based sin base_path). Postgres 14 y anteriores usan
 * `NULLS DISTINCT` por defecto — eso significa dos NULLs NO chocan, asi que
 * el constraint sigue funcionando aunque no usemos la sintaxis explicita.
 *
 * El down() elimina el constraint. NO borra los datos mergeados — esos
 * quedan marcados con merged_into_id por la migration Stage 1.
 */
return new class extends Migration {
    public function up(): void
    {
        $remaining = DB::table('system_settings')
            ->where('key', 'storage.duplicates_remaining')
            ->value('value');

        $remainingCount = $remaining !== null ? (int) $remaining : null;

        if ($remainingCount === null) {
            // No se ha corrido detect todavia: corremos una verificacion
            // directa para no bloquear al operador si olvida correr detect.
            $dupCount = (int) DB::selectOne("
                SELECT count(*) AS n FROM (
                    SELECT kind, physical_path_normalized
                    FROM storage_providers
                    WHERE merged_into_id IS NULL
                      AND physical_path_normalized IS NOT NULL
                    GROUP BY kind, physical_path_normalized
                    HAVING count(*) > 1
                ) d
            ")->n;

            if ($dupCount > 0) {
                throw new \RuntimeException(sprintf(
                    'storage.duplicates_remaining no está seteado y hay %d pares duplicados activos. Corra `php artisan storages:detect-duplicate-paths` para verlos y `storages:merge-duplicates --apply` para consolidarlos antes de aplicar esta migration.',
                    $dupCount
                ));
            }
        } elseif ($remainingCount > 0) {
            throw new \RuntimeException(sprintf(
                'storage.duplicates_remaining = %d. Ejecute `php artisan storages:merge-duplicates --canonical=<id> --duplicate=<id> --apply` para cada par hasta que duplicates_remaining = 0 antes de aplicar esta migration.',
                $remainingCount
            ));
        }

        // Activar el UNIQUE constraint. La columna physical_path_normalized
        // es STORED generated (definida en Stage 1). El indice parcial WHERE
        // physical_path_normalized IS NOT NULL evita que dos storages sin
        // base_path (config-based) choquen entre si.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX storage_providers_unique_physical_path_idx
            ON storage_providers (kind, physical_path_normalized)
            WHERE physical_path_normalized IS NOT NULL
        SQL);

        // Stage 1 creó un index NO-unique sobre las mismas columnas. Lo
        // eliminamos porque queda redundante (el UNIQUE lo cubre).
        DB::statement('DROP INDEX IF EXISTS storage_providers_physical_path_normalized_idx');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS storage_providers_unique_physical_path_idx');
        DB::statement(<<<'SQL'
            CREATE INDEX storage_providers_physical_path_normalized_idx
            ON storage_providers (kind, physical_path_normalized)
            WHERE physical_path_normalized IS NOT NULL
        SQL);
    }
};
