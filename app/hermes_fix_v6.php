<?php
/**
 * Hermes Fix v6: Reparent masivo con path matching.
 * Para cada espejo vacío, encuentra el "real" en storage padre cuyo path
 * comparta el mismo patrón y tenga hijos.
 *
 * Estrategia:
 * - Espejo E en storage S (con parent_storage_id P)
 * - Buscar real R en P donde path LIKE '%/E.name' (mismo segmento final) Y tenga hijos
 * - Si no hay matching por path exacto, busca el más cercano por nombre + orden
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$batchSize = 50;
$maxIteraciones = 80;

$tTotal = microtime(true);
$totalArchivosReparent = 0;
$totalParesReparados = 0;
$iteraciones = 0;

echo "=== Hermes Fix v6 — Reparent con path matching ===\n\n";

while ($iteraciones < $maxIteraciones) {
    $iteraciones++;

    // Espejos sin hijos en storage S, con parent_storage_id P
    // Devolver un lote de espejos para procesar uno a uno en PHP
    $espejos = DB::select("
      SELECT e.id AS espejo_id, e.storage_provider_id, sp.parent_storage_id AS psid, e.name
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

    if (count($espejos) === 0) {
        echo "Iter $iteraciones: sin espejos pendientes\n";
        break;
    }

    $procesados = 0;
    foreach ($espejos as $esp) {
        // Buscar el real que tenga hijos (preferir path coincidente)
        $real = DB::selectOne("
          SELECT id, storage_provider_id FROM files
          WHERE deleted_at IS NULL AND parent_id IS NOT NULL
            AND is_folder = true
            AND name = ?
            AND storage_provider_id = ?
            AND EXISTS (SELECT 1 FROM files c WHERE c.parent_id = files.id AND c.deleted_at IS NULL)
          ORDER BY (path LIKE '%/' || ?) DESC, id ASC
          LIMIT 1
        ", [$esp->name, $esp->psid, $esp->name]);

        if (!$real) continue;

        $count = DB::table('files')
            ->where('parent_id', $real->id)
            ->whereNull('deleted_at')
            ->count();

        if ($count === 0) continue;

        $affected = DB::table('files')
            ->where('parent_id', $real->id)
            ->whereNull('deleted_at')
            ->update([
                'parent_id' => $esp->espejo_id,
                'storage_provider_id' => $esp->storage_provider_id,
                'updated_at' => now(),
            ]);

        DB::table('file_mirror_audit_log')->insert([
            'action' => 'resync_snapshot',
            'mirror_file_id' => $real->id,
            'canonical_file_id' => $esp->espejo_id,
            'metadata' => json_encode([
                'storage_id' => $esp->storage_provider_id,
                'fecha' => $esp->name,
                'files_reparented' => $affected,
                'method' => 'v6_path_match',
            ]),
            'created_at' => now(),
        ]);

        $totalArchivosReparent += $affected;
        $totalParesReparados++;
        $procesados++;
    }

    echo "Iter $iteraciones: " . count($espejos) . " revisados, $procesados reparados (total=$totalParesReparados)\n";

    if ($totalParesReparados > 0 && $iteraciones % 10 === 0) {
        $elapsed = round(microtime(true) - $tTotal, 1);
        echo "  [checkpoint] {$elapsed}s, archivos reparentados: $totalArchivosReparent\n";
    }
}

$elapsed = round(microtime(true) - $tTotal, 2);

echo "\n========================================\n";
echo "=== RESUMEN ===\n";
echo "========================================\n";
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
echo "=== Hermes Fix v6 aplicado ===\n";
