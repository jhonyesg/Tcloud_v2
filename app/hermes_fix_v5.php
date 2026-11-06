<?php
/**
 * Hermes Fix v5: Aplica reparent masivo para todos los espejos vacíos.
 * Para cada espejo con 0 hijos:
 *   1. Encuentra el "real" en el storage padre (mismo nombre)
 *   2. Repunta los hijos del real al espejo
 *   3. También actualiza storage_provider_id si difiere
 *
 * Procesa en bloques de 50 para evitar cuelgue por timeout.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$batchSize = 50;
$maxIteraciones = 80;  // 80 * 50 = 4,000 max iteraciones

$tTotal = microtime(true);
$totalArchivosReparent = 0;
$totalParesReparados = 0;
$iteraciones = 0;

echo "=== Hermes Fix v5 — Reparent masivo ===\n\n";

while ($iteraciones < $maxIteraciones) {
    $iteraciones++;

    $ids = DB::select("
      SELECT e.id AS espejo_id, e.storage_provider_id, sp.parent_storage_id AS real_sid, e.name,
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
      ORDER BY e.id
      LIMIT $batchSize
    ");

    if (count($ids) === 0) {
        echo "Iter $iteraciones: sin espejos pendientes\n";
        break;
    }

    $procesados = 0;
    foreach ($ids as $par) {
        if ($par->real_id === null) continue;

        // Contar hijos del real
        $count = DB::table('files')
            ->where('parent_id', $par->real_id)
            ->whereNull('deleted_at')
            ->count();

        if ($count === 0) continue;

        // Repuntar archivos del real al espejo, ajustando también storage_provider_id
        $affected = DB::table('files')
            ->where('parent_id', $par->real_id)
            ->whereNull('deleted_at')
            ->update([
                'parent_id' => $par->espejo_id,
                'storage_provider_id' => $par->storage_provider_id,
                'updated_at' => now(),
            ]);

        DB::table('file_mirror_audit_log')->insert([
            'action' => 'resync_snapshot',
            'mirror_file_id' => $par->real_id,
            'canonical_file_id' => $par->espejo_id,
            'metadata' => json_encode([
                'storage_id' => $par->storage_provider_id,
                'fecha' => $par->name,
                'files_reparented' => $affected,
                'method' => 'v5_batched',
            ]),
            'created_at' => now(),
        ]);

        $totalArchivosReparent += $affected;
        $totalParesReparados++;
        $procesados++;
    }

    echo "Iter $iteraciones: " . count($ids) . " revisados, $procesados reparados (" . $totalParesReparados . " total)\n";
}

$elapsed = round(microtime(true) - $tTotal, 2);

echo "\n========================================\n";
echo "=== RESUMEN ===\n";
echo "========================================\n";
echo "Iteraciones: $iteraciones\n";
echo "Pares reparados: $totalParesReparados\n";
echo "Archivos reparentados: $totalArchivosReparent\n";
echo "Tiempo: {$elapsed}s\n\n";

$restantes = DB::select("
  SELECT COUNT(*) AS cnt
  FROM files e
  JOIN storage_providers sp ON sp.id = e.storage_provider_id
  LEFT JOIN files children ON children.parent_id = e.id AND children.deleted_at IS NULL
  WHERE e.parent_id IS NULL AND e.is_folder = true
    AND e.deleted_at IS NULL
    AND e.name ~ '^[0-9]{8}\$'
    AND sp.parent_storage_id IS NOT NULL
    AND children.id IS NULL
")[0]->cnt;

echo "Espejos sin hijos restantes: $restantes\n";
echo "=== Hermes Fix v5 aplicado ===\n";
