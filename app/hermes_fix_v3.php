<?php
/**
 * Hermes Fix v3: Versión optimizada sin CTE recursiva.
 * Procesa espejos en bloques pequeños para evitar timeouts.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = ($argv[1] ?? '') !== '--apply';
$batchSize = 50;
$maxIteraciones = 100;

echo "=== Hermes Fix v3 ===\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n\n";

// Paso 1: IDs de los espejos vacíos (con parent_id NULL, formato DDMMYYYY, sin hijos)
// Una sola query, sin recursividad
echo "Paso 1: Identificando espejos vacíos (con su real)...\n";

$ids = DB::select("
  SELECT e.id AS espejo_id, e.storage_provider_id, e.name, sp.parent_storage_id AS real_storage_id,
    (SELECT r.id FROM files r
       WHERE r.deleted_at IS NULL AND r.parent_id IS NOT NULL
         AND r.is_folder = true AND r.name = e.name
         AND r.storage_provider_id = sp.parent_storage_id
       ORDER BY r.id DESC LIMIT 1) AS real_id
  FROM files e
  JOIN storage_providers sp ON sp.id = e.storage_provider_id
  LEFT JOIN files children ON children.parent_id = e.id AND children.deleted_at IS NULL
  WHERE e.parent_id IS NULL
    AND e.is_folder = true
    AND e.deleted_at IS NULL
    AND e.name ~ '^[0-9]{8}\$'
    AND sp.parent_storage_id IS NOT NULL
    AND children.id IS NULL
  LIMIT 100
");

echo "Espejos vacíos (en este lote): " . count($ids) . "\n\n";

if (count($ids) === 0) {
    echo "No espejos vacíos pendientes.\n";
    exit(0);
}

// Paso 2: Procesar uno por uno, sin subqueries
echo "Paso 2: Procesando...\n";
$totalArchivosReparent = 0;
$paresReparados = 0;
$tStart = microtime(true);

foreach ($ids as $par) {
    if ($par->real_id === null) continue;

    // Contar hijos del real con query simple
    $count = DB::table('files')
        ->where('parent_id', $par->real_id)
        ->whereNull('deleted_at')
        ->count();

    if ($count === 0) continue;

    if ($dryRun) {
        echo "  [DRY] {$par->name} (espejo_id={$par->espejo_id} → real={$par->real_id}, n=$count)\n";
    } else {
        $affected = DB::table('files')
            ->where('parent_id', $par->real_id)
            ->whereNull('deleted_at')
            ->update(['parent_id' => $par->espejo_id, 'updated_at' => now()]);

        DB::table('file_mirror_audit_log')->insert([
            'action' => 'reparent_to_espejo',
            'mirror_file_id' => $par->real_id,
            'canonical_file_id' => $par->espejo_id,
            'metadata' => json_encode([
                'storage_id' => $par->storage_provider_id,
                'fecha' => $par->name,
                'files_reparented' => $affected,
            ]),
            'created_at' => now(),
        ]);

        $totalArchivosReparent += $affected;
        $paresReparados++;
    }
}

$elapsed = round(microtime(true) - $tStart, 2);
echo "Procesado en {$elapsed}s\n";

if ($dryRun) {
    echo "\n=== DRY-RUN: ningún cambio aplicado. ===\n";
    exit(0);
}

echo "\n=== Resultado ===\n";
echo "Pares reparados: $paresReparados\n";
echo "Archivos reparentados: $totalArchivosReparent\n";
echo "Auditados en file_mirror_audit_log\n";
echo "\n=== Hermes Fix v3 aplicado. ===\n";
