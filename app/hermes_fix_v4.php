<?php
/**
 * Hermes Fix v4: Loop completo en bloques de 100 espejos.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$batchSize = 100;
$maxIteraciones = 200;  // 200 * 100 = 20.000 mirrors max

$totalArchivosReparent = 0;
$totalParesReparados = 0;
$iteraciones = 0;

$tTotal = microtime(true);

echo "=== Hermes Fix v4 — Aplicando reparent a espejos vacíos ===\n\n";

while ($iteraciones < $maxIteraciones) {
    $iteraciones++;

    // Cada iteración toma un lote de 100 espejos
    $ids = DB::select("
      SELECT e.id AS espejo_id, e.storage_provider_id, e.name,
        sp.parent_storage_id AS real_storage_id,
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

    echo "Iter $iteraciones: " . count($ids) . " espejos a revisar\n";

    $procesados = 0;
    foreach ($ids as $par) {
        if ($par->real_id === null) continue;

        $count = DB::table('files')
            ->where('parent_id', $par->real_id)
            ->whereNull('deleted_at')
            ->count();

        if ($count === 0) continue;

        $affected = DB::table('files')
            ->where('parent_id', $par->real_id)
            ->whereNull('deleted_at')
            ->update(['parent_id' => $par->espejo_id, 'updated_at' => now()]);

        DB::table('file_mirror_audit_log')->insert([
            'action' => 'resync_snapshot',
            'mirror_file_id' => $par->real_id,
            'canonical_file_id' => $par->espejo_id,
            'metadata' => json_encode([
                'storage_id' => $par->storage_provider_id,
                'fecha' => $par->name,
                'files_reparented' => $affected,
                'method' => 'v4_batched',
            ]),
            'created_at' => now(),
        ]);

        $totalArchivosReparent += $affected;
        $totalParesReparados++;
        $procesados++;
    }

    echo "  Reparados en este lote: $procesados\n";
}

$elapsed = round(microtime(true) - $tTotal, 2);

echo "\n========================================\n";
echo "=== RESUMEN FINAL ===\n";
echo "========================================\n";
echo "Iteraciones: $iteraciones\n";
echo "Pares reparados: $totalParesReparados\n";
echo "Archivos reparentados: $totalArchivosReparent\n";
echo "Tiempo total: {$elapsed}s\n\n";

// Verificación post-fix
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
echo "Auditados en file_mirror_audit_log (acción: reparent_to_espejo_v4)\n";
echo "\n=== Hermes Fix v4 aplicado. ===\n";
