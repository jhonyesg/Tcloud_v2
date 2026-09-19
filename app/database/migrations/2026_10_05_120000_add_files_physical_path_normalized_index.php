<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Change `files-mirror-elimination` (Task 1.4):
 *
 * Índice funcional sobre la identidad física, calculada desde
 * `base_path_snapshot` (denormalizado por `FileObserver`):
 *
 *   LOWER(RTRIM(base_path_snapshot, '/') || '/' || LTRIM(path, '/'))
 *
 * Es la expresión que usa `FilePhysicalIdentity` para resolver el row
 * canónico en tiempo de lectura. Sin el índice, cada resolución es un seq
 * scan sobre ~1.5M filas.
 *
 * Por qué solo `base_path_snapshot` y no un JOIN a `storage_providers`:
 * un índice funcional de PostgreSQL solo puede referenciar columnas de su
 * propia tabla. El fallback a `storage_providers.base_path` (para las
 * 744.126 filas sin snapshot) se resuelve en el service con un segundo
 * query, que corre solo sobre el subconjunto `base_path_snapshot IS NULL`
 * — subconjunto que el plan de migración reduce a ~0 al correr
 * `files:resync-base-path-snapshots --apply` antes.
 *
 * NO es UNIQUE: hay 30.257 identidades físicas duplicadas entre filas
 * canónicas (hasta 20 copias). Consolidarlas requiere una decisión del
 * operador y va en un change posterior.
 *
 * CONCURRENTLY: la tabla es grande y el sitio está en producción; un CREATE
 * INDEX normal bloquea writes sobre `files` durante minutos. `CONCURRENTLY`
 * no puede correr dentro de una transacción, así que va suelto — si falla,
 * queda un índice INVALID que hay que dropear y recrear.
 */
return new class extends Migration {
    private const INDEX_NAME = 'files_physical_path_normalized_idx';

    public function up(): void
    {
        DB::statement(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON files (%s)',
            self::INDEX_NAME,
            self::expression('base_path_snapshot', 'path')
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX_NAME);
    }

    /**
     * Expresión de identidad física. Debe coincidir textualmente con la que
     * usa `FilePhysicalIdentity::equivalentIds()` para que el planner use el
     * índice.
     */
    public static function expression(string $baseColumn, string $pathColumn): string
    {
        return "LOWER(RTRIM($baseColumn, '/') || '/' || LTRIM($pathColumn, '/'))";
    }
};
